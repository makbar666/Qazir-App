<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockAlertService
{
    public function lowForOutlet(int $businessId, int $outletId): Collection
    {
        $incoming = DB::table('purchase_order_items as i')
            ->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->where('po.business_id', $businessId)->where('po.outlet_id', $outletId)
            ->whereIn('po.status', ['ordered', 'partial'])
            ->select('i.product_id', DB::raw('SUM(i.quantity - i.received_quantity) as incoming_quantity'))
            ->groupBy('i.product_id');

        return DB::table('stock_alert_rules as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('stocks as s', fn ($join) => $join->on('s.product_id', '=', 'p.id')->where('s.outlet_id', $outletId))
            ->leftJoinSub($incoming, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->where('r.business_id', $businessId)->where('r.outlet_id', $outletId)
            ->where('p.business_id', $businessId)->where('p.active', true)->whereNull('p.deleted_at')
            ->whereRaw('COALESCE(s.quantity, 0) <= r.minimum_quantity')
            ->select('p.id', 'p.name', 'p.sku', 'r.minimum_quantity', 'r.target_quantity',
                DB::raw('COALESCE(s.quantity, 0) as quantity'),
                DB::raw('COALESCE(incoming.incoming_quantity, 0) as incoming_quantity'))
            ->orderBy('p.name')->get()->map(function ($product) {
                $product->suggested_quantity = max(0, $product->target_quantity - $product->quantity - $product->incoming_quantity);
                return $product;
            });
    }
}
