<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoyaltyReconciliationService
{
    public function __construct(private TandaClient $tanda) {}

    public function snapshot(object $connection, array $saleIds, array $reservationKeys=[]): array
    {
        $response=$this->tanda->request($connection,'POST','reconciliation',[
            'sale_ids'=>$saleIds,'reservation_keys'=>$reservationKeys,
        ]);
        if (!isset($response['business_id'],$response['sales'],$response['reservations'])
            || !is_array($response['sales']) || !is_array($response['reservations'])) {
            throw new RuntimeException('Respons rekonsiliasi Tanda tidak lengkap. Perbarui aplikasi Tanda.');
        }
        if (!$connection->remote_business_id || (int)$response['business_id']!==(int)$connection->remote_business_id) {
            throw new RuntimeException('Bisnis Tanda tidak cocok dengan koneksi Qazir. Periksa token integrasi.');
        }
        foreach ($saleIds as $id) {
            if (!array_key_exists($id,$response['sales'])) throw new RuntimeException('Ada penjualan yang tidak dijawab oleh Tanda.');
        }
        foreach ($reservationKeys as $key) {
            if (!array_key_exists($key,$response['reservations'])) throw new RuntimeException('Ada reservasi yang tidak dijawab oleh Tanda.');
        }
        return $response;
    }

    public function check(object $connection, int $businessId, string $from, string $to, int $userId): int
    {
        $query=DB::table('sales')->where('business_id',$businessId)->whereNotNull('member_external_id')
            ->where(fn($q)=>$q->whereBetween(DB::raw('date(created_at)'),[$from,$to])
                ->orWhereBetween(DB::raw('date(paid_at)'),[$from,$to])
                ->orWhereBetween(DB::raw('date(refunded_at)'),[$from,$to]))
            ->where(fn($q)=>$q->whereIn('status',['paid','refunded'])->orWhere(fn($x)=>$x->where('status','cancelled')->where('redeem_points','>',0)))
            ->orderBy('id');
        if ((clone $query)->count()>2000) throw new RuntimeException('Periode berisi lebih dari 2.000 penjualan member. Pilih rentang tanggal lebih pendek.');

        $counts=['ok'=>0,'retry'=>0,'review'=>0];
        $items=[];
        foreach ($query->get()->chunk(100) as $chunk) {
            $sales=$chunk->values();
            $keys=$sales->filter(fn($s)=>(int)$s->redeem_points>0)->pluck('request_key')->all();
            $remote=$this->snapshot($connection,$sales->pluck('id')->map(fn($id)=>(string)$id)->all(),$keys);
            foreach ($sales as $sale) {
                $remoteSale=$remote['sales'][(string)$sale->id]??null;
                $reservation=$remote['reservations'][$sale->request_key]??null;
                [$result,$reason]=$this->compare($sale,$remoteSale,$reservation,
                    DB::table('loyalty_outlet_maps')->where('business_id',$businessId)->where('outlet_id',$sale->outlet_id)->value('remote_outlet_id'));
                $counts[$result]++;
                $items[]=['sale_id'=>$sale->id,'result'=>$result,'reason'=>$reason,
                    'remote_snapshot'=>json_encode(['sale'=>$remoteSale,'reservation'=>$reservation],JSON_THROW_ON_ERROR),
                    'created_at'=>now(),'updated_at'=>now()];
            }
        }

        return DB::transaction(function () use ($businessId,$from,$to,$userId,$counts,$items) {
            $runId=DB::table('loyalty_reconciliations')->insertGetId([
                'business_id'=>$businessId,'checked_by'=>$userId,'date_from'=>$from,'date_to'=>$to,
                'checked_count'=>count($items),'ok_count'=>$counts['ok'],'retry_count'=>$counts['retry'],
                'review_count'=>$counts['review'],'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach (array_chunk($items,200) as $batch) {
                DB::table('loyalty_reconciliation_items')->insert(array_map(fn($item)=>['loyalty_reconciliation_id'=>$runId]+$item,$batch));
            }
            return $runId;
        });
    }

    public function compare(object $sale, ?array $remote, ?array $reservation, ?int $mappedOutlet): array
    {
        if ($sale->status==='cancelled') {
            if ($remote) return ['review','Penjualan dibatalkan di Qazir tetapi transaksi masih tercatat di Tanda'];
            if (!$reservation || $reservation['status']==='released') return ['ok','Reservasi sudah dilepas'];
            return $reservation['status']==='reserved'
                ? ['retry','Reservasi masih tertahan di Tanda']
                : ['review','Reservasi telah dipakai, tetapi penjualan Qazir dibatalkan'];
        }
        if (!$mappedOutlet) return ['review','Outlet belum dipasangkan ke Tanda'];
        if (!$remote) return $sale->loyalty_transaction_id
            ? ['review','Qazir mencatat ID Tanda, tetapi transaksi tidak ditemukan pada koneksi ini']
            : ['retry','Penjualan belum tercatat di Tanda'];
        if ((int)$remote['member_id']!==(int)$sale->member_external_id
            || (int)$remote['outlet_id']!==$mappedOutlet
            || (int)$remote['total']!==(int)$sale->total
            || (int)$remote['redeem_points']!==(int)$sale->redeem_points
            || (int)$remote['redeem_rupiah']!==(int)$sale->redeem_rupiah
            || ($sale->loyalty_transaction_id && (int)$remote['transaction_id']!==(int)$sale->loyalty_transaction_id)) {
            return ['review','Member, outlet, nominal, poin, atau ID transaksi berbeda'];
        }
        if ($sale->redeem_points && (!$reservation || $reservation['status']!=='confirmed'
            || (int)$reservation['points']!==(int)$sale->redeem_points)) {
            return ['review','Reservasi penukaran poin tidak cocok'];
        }
        if ($sale->status==='refunded' && $remote['status']==='paid') return ['retry','Refund belum tercatat di Tanda'];
        if ($sale->status!==$remote['status']) return ['review','Status penjualan Qazir dan Tanda berbeda'];
        if ($sale->loyalty_earned_points!==null && (int)$sale->loyalty_earned_points!==(int)$remote['earned_points']) {
            return ['review','Perolehan poin berbeda'];
        }
        if ($sale->loyalty_status==='synced' && $sale->loyalty_earned_points===null) {
            return ['review','Qazir belum menyimpan jumlah poin yang diterima dari Tanda'];
        }
        if ($sale->loyalty_status!=='synced' || ($sale->status==='refunded' && $sale->loyalty_refund_status!=='synced')) {
            return $sale->loyalty_transaction_id
                ? ['review','Status lokal belum sinkron meski transaksi Tanda sudah ada']
                : ['retry','Transaksi ada di Tanda; status lokal belum diperbarui'];
        }
        return ['ok','Sesuai'];
    }
}
