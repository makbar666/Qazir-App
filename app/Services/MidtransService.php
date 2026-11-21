<?php

namespace App\Services;

use App\Events\SalePaymentUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MidtransService
{
    public function settings(int $businessId): ?object {return DB::table('midtrans_settings')->where('business_id',$businessId)->where('active',true)->first();}
    private function credentials(int $businessId): ?object {return DB::table('midtrans_settings')->where('business_id',$businessId)->first();}
    private function base(object $settings): string {return $settings->environment==='production'?'https://api.midtrans.com':'https://api.sandbox.midtrans.com';}
    private function http(object $settings)
    {
        $bundle=config('services.midtrans.ca_bundle');
        if(!is_string($bundle)||!is_file($bundle))throw new RuntimeException('Bundle sertifikat CA Midtrans tidak ditemukan.');
        return Http::timeout(12)->withBasicAuth(decrypt($settings->server_key),'')->acceptJson()->withOptions(['verify'=>$bundle]);
    }

    private function recoverQr(int $saleId,object $settings,string $transactionId): bool
    {
        $url=$this->base($settings).'/v2/qris/'.rawurlencode($transactionId).'/qr-code';
        $response=$this->http($settings)->get($url);
        if(!$response->successful()||!str_starts_with(strtolower((string)$response->header('Content-Type')),'image/png'))return false;
        $sale=DB::table('sales')->where('id',$saleId)->first();
        if($sale?->status!=='pending')return false;
        $fields=['midtrans_qr_url'=>$url,'updated_at'=>now()];
        if($sale->midtrans_status!=='cancel_requested')$fields+=['midtrans_status'=>'pending','midtrans_error'=>null];
        DB::table('sales')->where('id',$saleId)->where('status','pending')->update($fields);
        return true;
    }

    public function createCharge(int $saleId): bool
    {
        $sale=DB::table('sales')->where('id',$saleId)->first();if(!$sale||$sale->payment_method!=='qris'||$sale->status!=='pending')return false;
        $settings=$this->settings($sale->business_id);if(!$settings)return false;
        try{
            $response=$this->http($settings)->post($this->base($settings).'/v2/charge',['payment_type'=>'qris','transaction_details'=>['order_id'=>$sale->number,'gross_amount'=>$sale->total],'qris'=>['acquirer'=>'gopay']]);
            if($response->status()===401)throw new RuntimeException('Midtrans menolak Server Key (HTTP 401). Periksa Server Key dan lingkungan Sandbox/Production di menu QRIS Midtrans.');
            if(!$response->successful())throw new RuntimeException('Midtrans HTTP '.$response->status().': '.substr((string)($response->json('status_message')??$response->body()),0,250));
            $data=$response->json();if(($data['order_id']??null)!==$sale->number||!in_array($data['transaction_status']??null,['pending','settlement'],true))throw new RuntimeException('Respons Midtrans tidak cocok.');
            if(!empty($data['transaction_id']))DB::table('sales')->where('id',$saleId)->where('status','pending')->update(['midtrans_transaction_id'=>$data['transaction_id'],'updated_at'=>now()]);
            $qr=collect($data['actions']??[])->firstWhere('name','generate-qr-code-v2')??collect($data['actions']??[])->firstWhere('name','generate-qr-code');
            $url=$qr['url']??null;
            if(is_string($url)&&str_starts_with($url,$this->base($settings).'/')){
                DB::table('sales')->where('id',$saleId)->where('status','pending')->update(['midtrans_qr_url'=>$url,'midtrans_status'=>'pending','midtrans_error'=>null,'updated_at'=>now()]);
                return true;
            }
            if(!empty($data['transaction_id'])&&$this->recoverQr($saleId,$settings,$data['transaction_id']))return true;
            throw new RuntimeException('Gambar QR Midtrans belum tersedia. Periksa status transaksi sebelum mencoba lagi.');
        }catch(Throwable $e){Log::warning('Midtrans charge failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);DB::table('sales')->where('id',$saleId)->where('status','pending')->update(['midtrans_status'=>'charge_failed','midtrans_error'=>$e->getMessage(),'updated_at'=>now()]);return false;}
    }

    public function status(int $saleId): array
    {
        $sale=DB::table('sales')->where('id',$saleId)->first();if(!$sale||$sale->payment_method!=='qris')throw new RuntimeException('Penjualan QRIS tidak ditemukan.');
        $settings=$this->credentials($sale->business_id);if(!$settings)throw new RuntimeException('Konfigurasi Midtrans tidak tersedia.');
        $response=$this->http($settings)->get($this->base($settings).'/v2/'.rawurlencode($sale->number).'/status');
        if($response->status()===401)throw new RuntimeException('Midtrans menolak Server Key (HTTP 401). Periksa Server Key dan lingkungan Sandbox/Production di menu QRIS Midtrans.');
        if(!$response->successful())throw new RuntimeException('Status Midtrans belum tersedia (HTTP '.$response->status().').');
        $data=$response->json();
        DB::table('midtrans_refunds')->where('sale_id',$saleId)->update(['last_checked_at'=>now()]);
        $this->applyStatus($sale,$data);
        $fresh=DB::table('sales')->where('id',$saleId)->first();
        if($fresh->status==='pending'&&!$fresh->midtrans_qr_url&&$fresh->midtrans_transaction_id)
            $this->recoverQr($saleId,$settings,$fresh->midtrans_transaction_id);
        return $data;
    }

    public function requestRefund(int $saleId, int $actorId, string $reason): void
    {
        $created=false;
        $refund=DB::transaction(function()use($saleId,$actorId,$reason,&$created){
            $sale=DB::table('sales')->where('id',$saleId)->lockForUpdate()->first();
            if(!$sale||$sale->status!=='paid'||$sale->payment_method!=='qris'
                ||!($sale->midtrans_transaction_id||$sale->midtrans_status==='settlement')){
                throw new RuntimeException('Penjualan QRIS Midtrans tidak dapat direfund.');
            }
            if(!$this->credentials($sale->business_id))throw new RuntimeException('Konfigurasi Midtrans tidak tersedia.');
            $existing=DB::table('midtrans_refunds')->where('sale_id',$saleId)->first();
            if($existing){
                if(in_array($existing->status,['request_failed','review'],true)){
                    throw new RuntimeException('Permintaan refund perlu diperiksa. Gunakan pemeriksaan status pada detail penjualan.');
                }
                return $existing;
            }
            if($sale->midtrans_status==='refund_review'){
                throw new RuntimeException('Midtrans mencatat refund di luar POS. Periksa transaksi sebelum membuat permintaan baru.');
            }
            $created=true;
            $id=DB::table('midtrans_refunds')->insertGetId([
                'sale_id'=>$saleId,'business_id'=>$sale->business_id,'outlet_id'=>$sale->outlet_id,
                'requested_by'=>$actorId,'refund_key'=>'POSREF-'.Str::uuid(),
                'amount'=>$sale->total,'reason'=>$reason,'status'=>'preparing',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            return DB::table('midtrans_refunds')->where('id',$id)->first();
        },3);
        if($created)$this->sendRefundRequest($refund);
    }

    public function retryRefund(int $saleId): void
    {
        $refund=DB::table('midtrans_refunds')->where('sale_id',$saleId)->first();
        if(!$refund)throw new RuntimeException('Permintaan refund tidak ditemukan.');
        if($refund->status==='confirmed')return;
        try{$this->status($saleId);}catch(Throwable $e){
            Log::warning('Midtrans refund status before retry failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);
        }
        $refund=DB::table('midtrans_refunds')->where('sale_id',$saleId)->first();
        if($refund->status==='confirmed'||$refund->status==='pending')return;
        if(!in_array($refund->status,['preparing','request_failed'],true)){
            throw new RuntimeException('Status refund memerlukan pemeriksaan manual di Midtrans.');
        }
        $this->sendRefundRequest($refund);
    }

    private function sendRefundRequest(object $refund): void
    {
        $sale=DB::table('sales')->where('id',$refund->sale_id)->first();
        $settings=$this->credentials($sale->business_id);
        if(!$settings)throw new RuntimeException('Konfigurasi Midtrans tidak tersedia.');
        try{
            $response=$this->http($settings)->post($this->base($settings).'/v2/'.rawurlencode($sale->number).'/refund',[
                'refund_key'=>$refund->refund_key,'amount'=>$refund->amount,'reason'=>$refund->reason,
            ]);
            $data=$response->json() ?: [];
            if(!$response->successful()||($data['status_code']??null)!=='200'){
                throw new RuntimeException('Midtrans menolak refund (HTTP '.$response->status().'): '.substr((string)($data['status_message']??'Periksa status transaksi.'),0,180));
            }
            if(($data['order_id']??null)!==$sale->number||($data['payment_type']??null)!=='qris'
                ||($data['refund_key']??null)!==$refund->refund_key
                ||($data['transaction_status']??null)!=='refund'
                ||!$this->matchesAmount($data['gross_amount']??null,$sale->total)
                ||!$this->matchesAmount($data['refund_amount']??null,$refund->amount)){
                throw new RuntimeException('Respons refund Midtrans tidak cocok. Periksa status sebelum mencoba lagi.');
            }
            DB::table('midtrans_refunds')->where('id',$refund->id)->whereIn('status',['preparing','request_failed'])->update([
                'status'=>'pending','error'=>null,'requested_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('sales')->where('id',$sale->id)->where('status','paid')->update([
                'midtrans_status'=>'refund_pending','midtrans_error'=>null,'updated_at'=>now(),
            ]);
        }catch(Throwable $e){
            DB::table('midtrans_refunds')->where('id',$refund->id)->where('status','!=','confirmed')->update([
                'status'=>'request_failed','error'=>$e->getMessage(),'updated_at'=>now(),
            ]);
            Log::warning('Midtrans refund request failed',['sale_id'=>$sale->id,'error'=>$e->getMessage()]);
            throw $e;
        }
    }

    private function matchesAmount(mixed $value,int $expected): bool
    {
        $amount=(string)($value??'');
        return (bool)preg_match('/^\d+(?:\.00)?$/',$amount) && (int)$amount===$expected;
    }

    public function cancel(int $saleId): void
    {
        $sale=DB::table('sales')->where('id',$saleId)->where('payment_method','qris')->first();
        if(!$sale||$sale->status!=='pending')throw new RuntimeException('Transaksi ini tidak menunggu pembayaran QRIS.');
        $settings=$this->credentials($sale->business_id);
        if(!$settings)throw new RuntimeException('Konfigurasi Midtrans tidak tersedia.');

        if(!$sale->midtrans_transaction_id){
            $neverCreated=$sale->midtrans_status==='new'
                || str_contains((string)$sale->midtrans_error,'HTTP 401')
                || str_contains((string)$sale->midtrans_error,'cURL error 60')
                || str_contains((string)$sale->midtrans_error,'Bundle sertifikat CA');
            if($neverCreated){$this->applyStatus($sale,['order_id'=>$sale->number,'payment_type'=>'qris','gross_amount'=>(string)$sale->total,'transaction_status'=>'cancel']);return;}

            $status=$this->http($settings)->get($this->base($settings).'/v2/'.rawurlencode($sale->number).'/status');
            if($status->status()===404){$this->applyStatus($sale,['order_id'=>$sale->number,'payment_type'=>'qris','gross_amount'=>(string)$sale->total,'transaction_status'=>'cancel']);return;}
            if(!$status->successful())throw new RuntimeException('Status Midtrans belum dapat dipastikan. Pembatalan ditunda agar pembayaran tidak terlewat.');
            $this->applyStatus($sale,$status->json());
            $sale=DB::table('sales')->where('id',$saleId)->first();
            if($sale->status!=='pending')return;
        }

        $responseCode=null;
        try{
            $response=$this->http($settings)->post($this->base($settings).'/v2/'.rawurlencode($sale->number).'/cancel');
            $responseCode=$response->status();
            $data=$response->json();
            if($response->successful()&&($data['transaction_status']??null)==='cancel'){
                $this->applyStatus($sale,$data);
                return;
            }
        }catch(Throwable $e){Log::warning('Midtrans cancel request failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);}

        if($responseCode===null||$responseCode>=500){
            try{
                $expire=$this->http($settings)->post($this->base($settings).'/v2/'.rawurlencode($sale->number).'/expire');
                if($expire->successful()&&($expire->json('transaction_status')==='expire')){
                    $this->status($saleId);
                    if(DB::table('sales')->where('id',$saleId)->value('status')!=='pending')return;
                }
            }catch(Throwable $e){Log::warning('Midtrans expire request failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);}
        }

        try{$this->status($saleId);}catch(Throwable $e){Log::warning('Midtrans status after cancel failed',['sale_id'=>$saleId,'error'=>$e->getMessage()]);}
        if(DB::table('sales')->where('id',$saleId)->value('status')==='pending'){
            $message='Midtrans belum membatalkan transaksi'.($responseCode?' (HTTP '.$responseCode.')':'').'. Pembatalan akan dicoba lagi; stok tetap dipesan.';
            DB::table('sales')->where('id',$saleId)->where('status','pending')->update(['midtrans_status'=>'cancel_requested','midtrans_error'=>$message,'updated_at'=>now()]);
            throw new RuntimeException($message);
        }
    }

    public function webhook(array $data): void
    {
        $sale=DB::table('sales')->where('number',$data['order_id']??'')->where('payment_method','qris')->first();if(!$sale)throw new RuntimeException('Order tidak ditemukan.');
        $settings=$this->credentials($sale->business_id);if(!$settings)throw new RuntimeException('Konfigurasi Midtrans tidak tersedia.');
        foreach(['status_code','gross_amount','signature_key'] as $field)if(!isset($data[$field]))throw new RuntimeException('Notifikasi tidak lengkap.');
        $expected=hash('sha512',$data['order_id'].$data['status_code'].$data['gross_amount'].decrypt($settings->server_key));
        if(!hash_equals($expected,(string)$data['signature_key']))throw new RuntimeException('Tanda tangan notifikasi tidak valid.');
        $this->applyStatus($sale,$data);
    }

    private function applyRefundStatus(object $sale,array $data): bool
    {
        if(($data['status_code']??null)!=='200')throw new RuntimeException('Status refund Midtrans tidak valid.');
        if($sale->midtrans_transaction_id && isset($data['transaction_id'])
            && $data['transaction_id']!==$sale->midtrans_transaction_id){
            throw new RuntimeException('ID transaksi refund Midtrans tidak cocok.');
        }
        $refund=DB::table('midtrans_refunds')->where('sale_id',$sale->id)->lockForUpdate()->first();
        if(!$refund){
            if($sale->status==='paid')DB::table('sales')->where('id',$sale->id)->update([
                'midtrans_status'=>'refund_review','midtrans_error'=>'Midtrans mencatat refund di luar POS. Periksa nominal dan riwayat refund.','updated_at'=>now(),
            ]);
            return false;
        }
        if($refund->status==='confirmed'||$sale->status==='refunded')return false;
        $matched=collect($data['refunds']??[])->firstWhere('refund_key',$refund->refund_key);
        if(!$matched && ($data['refund_key']??null)===$refund->refund_key)$matched=$data;
        if(!$matched || ($data['transaction_status']??null)!=='refund'
            ||!$this->matchesAmount($matched['refund_amount']??null,$refund->amount)){
            DB::table('midtrans_refunds')->where('id',$refund->id)->update([
                'status'=>'review','error'=>'Status atau nominal refund Midtrans tidak cocok. Periksa di dashboard Midtrans.','updated_at'=>now(),
            ]);
            DB::table('sales')->where('id',$sale->id)->where('status','paid')->update([
                'midtrans_status'=>'refund_review','updated_at'=>now(),
            ]);
            return false;
        }
        if(empty($matched['bank_confirmed_at'])){
            DB::table('midtrans_refunds')->where('id',$refund->id)->update([
                'status'=>'pending','error'=>null,'requested_at'=>$refund->requested_at??now(),'updated_at'=>now(),
            ]);
            DB::table('sales')->where('id',$sale->id)->where('status','paid')->update([
                'midtrans_status'=>'refund_pending','updated_at'=>now(),
            ]);
            return false;
        }
        if($sale->status!=='paid')return false;
        if(!app(SaleRefundService::class)->complete($sale->id,$refund->requested_by,$refund->reason))return false;
        DB::table('midtrans_refunds')->where('id',$refund->id)->update([
            'status'=>'confirmed','error'=>null,'confirmed_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('sales')->where('id',$sale->id)->update([
            'midtrans_status'=>'refund','midtrans_error'=>null,'updated_at'=>now(),
        ]);
        return true;
    }

    private function applyStatus(object $sale,array $data): void
    {
        if(($data['order_id']??null)!==$sale->number||($data['payment_type']??null)!=='qris')throw new RuntimeException('Identitas pembayaran tidak cocok.');
        $gross=(string)($data['gross_amount']??'');
        if(!preg_match('/^\d+(?:\.00)?$/',$gross)||((int)$gross)!==(int)$sale->total)throw new RuntimeException('Nominal pembayaran tidak cocok.');
        $status=$data['transaction_status']??'';
        $settled=$status==='settlement'&&($data['status_code']??'')==='200'&&in_array(strtolower((string)($data['fraud_status']??'accept')),['accept'],true);
        $sync=false;$broadcastStatus=null;
        DB::transaction(function()use($sale,$data,$status,$settled,&$sync,&$broadcastStatus){
            $locked=DB::table('sales')->where('id',$sale->id)->lockForUpdate()->first();
            if(in_array($status,['refund','partial_refund'],true)){
                if($this->applyRefundStatus($locked,$data)){
                    $sync=(bool)$locked->member_external_id;
                    $broadcastStatus='refunded';
                }
                return;
            }
            if($settled){
                if($locked->status==='paid'||$locked->status==='refunded')return;
                if($locked->status!=='pending'){DB::table('sales')->where('id',$sale->id)->update(['midtrans_status'=>'payment_review','midtrans_error'=>'Midtrans menyatakan lunas setelah stok dilepas. Periksa transaksi ini.','updated_at'=>now()]);return;}
                DB::table('sales')->where('id',$sale->id)->update(['status'=>'paid','amount_received'=>$locked->total,'change_amount'=>0,'midtrans_status'=>'settlement','midtrans_transaction_id'=>$data['transaction_id']??$locked->midtrans_transaction_id,'paid_at'=>now(),'loyalty_status'=>$locked->member_external_id?'pending':'disabled','updated_at'=>now()]);$sync=(bool)$locked->member_external_id;$broadcastStatus='paid';return;
            }
            if(in_array($status,['expire','cancel','deny','failure'],true)&&$locked->status==='pending'){
                foreach(DB::table('sale_items')->where('sale_id',$sale->id)->get() as $item){DB::table('stocks')->where('outlet_id',$locked->outlet_id)->where('product_id',$item->product_id)->increment('quantity',$item->quantity);DB::table('stock_movements')->insert(['business_id'=>$locked->business_id,'outlet_id'=>$locked->outlet_id,'product_id'=>$item->product_id,'sale_id'=>$sale->id,'actor_id'=>$locked->cashier_id,'delta'=>$item->quantity,'reason'=>'payment_'.$status,'created_at'=>now(),'updated_at'=>now()]);}
                DB::table('sales')->where('id',$sale->id)->update(['status'=>'cancelled','midtrans_status'=>$status,'updated_at'=>now()]);$broadcastStatus='cancelled';
            }
        },3);
        if($broadcastStatus==='cancelled' && $sale->redeem_points)app(TandaClient::class)->release($sale->id);
        if($broadcastStatus){
            try{event(new SalePaymentUpdated($sale->id,$broadcastStatus));}
            catch(Throwable $e){Log::warning('Sale payment broadcast failed',['sale_id'=>$sale->id,'error'=>$e->getMessage()]);}
        }
        if($sync)app(TandaClient::class)->sync($sale->id);
    }
}
