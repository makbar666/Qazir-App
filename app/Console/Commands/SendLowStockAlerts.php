<?php

namespace App\Console\Commands;

use App\Mail\LowStockDigest;
use App\Services\StockAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendLowStockAlerts extends Command
{
    protected $signature = 'stock:alert-low {--outlet= : Batasi ke ID outlet tertentu}';
    protected $description = 'Kirim ringkasan stok menipis per outlet ke email yang diatur';

    public function handle(StockAlertService $alerts): int
    {
        $date = now(config('stock-alerts.timezone'))->toDateString();
        $query = DB::table('stock_alert_settings as s')->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->where('s.email_enabled', true)->select('s.*', 'o.name as outlet_name');
        if ($this->option('outlet')) $query->where('s.outlet_id', (int) $this->option('outlet'));
        $sent = 0;
        $failed = 0;
        foreach ($query->orderBy('s.outlet_id')->get() as $settings) {
            $products = $alerts->lowForOutlet($settings->business_id, $settings->outlet_id);
            if ($products->isEmpty()) continue;
            $recipients = json_decode($settings->recipient_emails, true) ?: [];
            foreach ($recipients as $email) {
                if (DB::table('stock_alert_deliveries')->where('outlet_id', $settings->outlet_id)
                    ->where('recipient_email', $email)->where('alert_date', $date)->exists()) continue;
                try {
                    Mail::to($email)->send(new LowStockDigest($settings->outlet_name, $products));
                    DB::table('stock_alert_deliveries')->insert([
                        'outlet_id' => $settings->outlet_id, 'recipient_email' => $email,
                        'alert_date' => $date, 'product_count' => $products->count(), 'sent_at' => now(),
                    ]);
                    $sent++;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('Gagal mengirim email stok menipis', [
                        'outlet_id' => $settings->outlet_id, 'recipient' => $email,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
        $this->info("Email stok menipis: {$sent} terkirim, {$failed} gagal.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
