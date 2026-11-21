<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaleRefundService
{
    public function complete(int $saleId, int $actorId, string $reason, ?int $cashShiftId = null): bool
    {
        return DB::transaction(function () use ($saleId, $actorId, $reason, $cashShiftId) {
            $sale = DB::table('sales')->where('id', $saleId)->lockForUpdate()->first();
            if (!$sale || $sale->status !== 'paid') return false;
            foreach (DB::table('sale_items')->where('sale_id', $saleId)->get() as $item) {
                DB::table('stocks')->where('outlet_id', $sale->outlet_id)->where('product_id', $item->product_id)
                    ->increment('quantity', $item->quantity);
                DB::table('stock_movements')->insert([
                    'business_id' => $sale->business_id, 'outlet_id' => $sale->outlet_id,
                    'product_id' => $item->product_id, 'sale_id' => $saleId, 'actor_id' => $actorId,
                    'delta' => $item->quantity, 'reason' => 'refund', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('sales')->where('id', $saleId)->update([
                'status' => 'refunded', 'refund_reason' => $reason, 'refunded_by' => $actorId,
                'refunded_at' => now(), 'updated_at' => now(),
            ]);
            app(CashShiftService::class)->recordRefund($sale, $actorId, $cashShiftId);
            return true;
        }, 3);
    }
}
