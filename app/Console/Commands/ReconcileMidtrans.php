<?php

namespace App\Console\Commands;

use App\Services\MidtransService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileMidtrans extends Command
{
    protected $signature='midtrans:reconcile';
    protected $description='Periksa status QRIS pending langsung ke Midtrans';
    public function handle(MidtransService $midtrans): int
    {
        $checked=0;
        DB::table('sales')->where('payment_method','qris')->where('status','pending')->whereNotNull('midtrans_transaction_id')->orderBy('id')->limit(50)->pluck('id')->each(function($id)use($midtrans,&$checked){try{if(DB::table('sales')->where('id',$id)->value('midtrans_status')==='cancel_requested')$midtrans->cancel($id);else $midtrans->status($id);$checked++;}catch(Throwable $e){Log::warning('Midtrans status check failed',['sale_id'=>$id,'error'=>$e->getMessage()]);}});
        DB::table('midtrans_refunds')->whereIn('status',['preparing','pending','request_failed','review'])
            ->where(fn($query)=>$query->whereNull('last_checked_at')->orWhere('last_checked_at','<',now()->subMinutes(5)))
            ->orderBy('id')->limit(20)->pluck('id')->each(function($id)use($midtrans,&$checked){
                $refund=DB::table('midtrans_refunds')->where('id',$id)->first();
                DB::table('midtrans_refunds')->where('id',$id)->update(['last_checked_at'=>now()]);
                try{$midtrans->status($refund->sale_id);$checked++;}
                catch(Throwable $e){Log::warning('Midtrans refund status check failed',['sale_id'=>$refund->sale_id,'error'=>$e->getMessage()]);}
            });
        $this->info("$checked pembayaran dan refund diperiksa.");return self::SUCCESS;
    }
}
