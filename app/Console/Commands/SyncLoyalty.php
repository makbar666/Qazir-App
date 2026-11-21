<?php

namespace App\Console\Commands;

use App\Services\TandaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLoyalty extends Command
{
    protected $signature='loyalty:sync';
    protected $description='Kirim ulang penjualan member dan refund yang belum tersinkronisasi';
    public function handle(TandaClient $client): int
    {
        $count=0;
        DB::table('sales')->where('status','pending_loyalty')->where('created_at','<',now()->subMinutes(2))->orderBy('id')->limit(100)->pluck('id')->each(fn($id)=>$client->abortDraft($id));
        DB::table('sales')->where('status','cancelled')->where('loyalty_status','release_pending')->orderBy('id')->limit(100)->pluck('id')->each(fn($id)=>$client->release($id));
        DB::table('sales')->whereNotNull('member_external_id')->where(function($q){$q->whereIn('loyalty_status',['pending','failed'])->orWhere(function($q){$q->where('status','refunded')->where(function($q){$q->whereNull('loyalty_refund_status')->orWhere('loyalty_refund_status','!=','synced');});});})->orderBy('id')->limit(100)->pluck('id')->each(function($id)use($client,&$count){if($client->sync($id))$count++;});
        $this->info("$count transaksi berhasil disinkronkan.");return self::SUCCESS;
    }
}
