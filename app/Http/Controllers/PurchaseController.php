<?php

namespace App\Http\Controllers;

use App\Support\OutletContext;
use App\Support\RoleAccess;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    private function canManage(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'purchases_manage'), 403);
    }

    private function order(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('purchase_orders')->where('id', $id)
            ->where('business_id', $request->user()->business_id)->where('outlet_id', OutletContext::id($request));
        if ($lock) $query->lockForUpdate();
        $order = $query->first();
        abort_unless($order, 404);
        return $order;
    }

    public function index(Request $request)
    {
        $outlet = OutletContext::id($request);
        return view('purchases', [
            'orders' => DB::table('purchase_orders as po')->join('users as u', 'u.id', '=', 'po.created_by')
                ->where('po.business_id', $request->user()->business_id)->where('po.outlet_id', $outlet)
                ->select('po.*', 'u.name as creator_name')->orderByDesc('po.id')->paginate(20),
            'outletName' => DB::table('outlets')->where('id', $outlet)->value('name'),
            'canManage' => RoleAccess::allowsAbility($request->user(), 'purchases_manage'),
        ]);
    }

    public function suppliers(Request $request)
    {
        return view('suppliers', [
            'suppliers' => DB::table('suppliers')->where('business_id', $request->user()->business_id)->orderBy('name')->get(),
            'canManage' => RoleAccess::allowsAbility($request->user(), 'purchases_manage'),
        ]);
    }

    public function storeSupplier(Request $request)
    {
        $this->canManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::table('suppliers')->insert([...$data, 'business_id' => $request->user()->business_id,
            'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return redirect()->route('purchases.suppliers')->with('success', 'Pemasok ditambahkan.');
    }

    public function updateSupplier(Request $request, int $id)
    {
        $this->canManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'], 'active' => ['required', 'boolean'],
        ]);
        $supplier = DB::table('suppliers')->where('id', $id)->where('business_id', $request->user()->business_id)->first();
        abort_unless($supplier, 404);
        DB::table('suppliers')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        return redirect()->route('purchases.suppliers')->with('success', 'Pemasok diperbarui.');
    }

    public function create(Request $request, StockAlertService $alerts)
    {
        $this->canManage($request);
        return view('purchase-create', [
            'suppliers' => DB::table('suppliers')->where('business_id', $request->user()->business_id)->where('active', true)->orderBy('name')->get(),
            'products' => DB::table('products')->where('business_id', $request->user()->business_id)
                ->whereNull('deleted_at')->where('active', true)->orderBy('name')->get(['id', 'sku', 'name']),
            'outletName' => DB::table('outlets')->where('id', OutletContext::id($request))->value('name'),
            'suggestions' => $request->boolean('suggested')
                ? $alerts->lowForOutlet($request->user()->business_id, OutletContext::id($request))
                    ->filter(fn ($product) => $product->suggested_quantity > 0)->keyBy('id')
                : collect(),
        ]);
    }

    public function store(Request $request)
    {
        $this->canManage($request);
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'supplier_id' => ['required', 'integer'],
            'supplier_reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $business = $request->user()->business_id;
        $existing = DB::table('purchase_orders')->where('business_id', $business)->where('request_key', $data['request_key'])->first();
        if ($existing) return redirect()->route('purchases.show', $existing->id);

        $id = DB::transaction(function () use ($request, $data, $business) {
            $existing = DB::table('purchase_orders')->where('business_id', $business)->where('request_key', $data['request_key'])->first();
            if ($existing) return $existing->id;
            $supplier = DB::table('suppliers')->where('id', $data['supplier_id'])->where('business_id', $business)->where('active', true)->first();
            if (!$supplier) throw ValidationException::withMessages(['supplier_id' => 'Pilih pemasok aktif milik usaha ini.']);
            $items = collect($data['items'])->sortBy('product_id')->values();
            $products = DB::table('products')->where('business_id', $business)->whereNull('deleted_at')->where('active', true)
                ->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
            if ($products->count() !== $items->count()) throw ValidationException::withMessages(['items' => 'Salah satu produk tidak tersedia.']);

            $id = DB::table('purchase_orders')->insertGetId([
                'business_id' => $business, 'outlet_id' => OutletContext::id($request),
                'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                'created_by' => $request->user()->id, 'request_key' => $data['request_key'],
                'number' => 'PO-'.now()->format('ymd').'-'.strtoupper(Str::random(8)),
                'status' => 'ordered', 'supplier_reference' => $data['supplier_reference'] ?? null,
                'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                DB::table('purchase_order_items')->insert([
                    'purchase_order_id' => $id, 'product_id' => $product->id,
                    'sku' => $product->sku, 'name' => $product->name,
                    'quantity' => $item['quantity'], 'unit_cost' => $item['unit_cost'],
                ]);
            }
            return $id;
        }, 3);
        return redirect()->route('purchases.show', $id)->with('success', 'Pesanan pembelian dibuat. Stok bertambah setelah barang diterima.');
    }

    public function show(Request $request, int $id)
    {
        $order = $this->order($request, $id);
        $items = DB::table('purchase_order_items')->where('purchase_order_id', $id)->orderBy('name')->get();
        $receipts = DB::table('purchase_receipts as r')->join('users as u', 'u.id', '=', 'r.received_by')
            ->where('r.purchase_order_id', $id)->select('r.*', 'u.name as receiver_name')->orderByDesc('r.id')->get();
        $receiptItems = DB::table('purchase_receipt_items as ri')->join('purchase_order_items as oi', 'oi.id', '=', 'ri.purchase_order_item_id')
            ->whereIn('ri.purchase_receipt_id', $receipts->pluck('id'))
            ->get(['ri.purchase_receipt_id', 'ri.quantity', 'oi.name']);
        return view('purchase', [
            'order' => $order, 'items' => $items, 'receipts' => $receipts, 'receiptItems' => $receiptItems,
            'canManage' => RoleAccess::allowsAbility($request->user(), 'purchases_manage'),
            'outletName' => DB::table('outlets')->where('id', $order->outlet_id)->value('name'),
        ]);
    }

    public function receive(Request $request, int $id)
    {
        $this->canManage($request);
        $data = $request->validate([
            'request_key' => ['required', 'uuid'], 'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $order = $this->order($request, $id, true);
            if (DB::table('purchase_receipts')->where('purchase_order_id', $id)->where('request_key', $data['request_key'])->exists()) return;
            if (!in_array($order->status, ['ordered', 'partial'], true)) {
                throw ValidationException::withMessages(['receipt' => 'Pesanan ini sudah selesai atau dibatalkan.']);
            }
            $items = DB::table('purchase_order_items')->where('purchase_order_id', $id)->orderBy('id')->get()->keyBy('id');
            $received = [];
            foreach ($data['quantities'] as $itemId => $quantity) {
                $item = $items->get((int) $itemId);
                if (!$item) throw ValidationException::withMessages(['quantities' => 'Produk tidak termasuk dalam pesanan ini.']);
                if ($quantity > $item->quantity - $item->received_quantity) {
                    throw ValidationException::withMessages(['quantities' => 'Jumlah '.$item->name.' melebihi sisa pesanan.']);
                }
                if ($quantity > 0) $received[] = [$item, (int) $quantity];
            }
            if (!$received) throw ValidationException::withMessages(['quantities' => 'Isi jumlah diterima untuk minimal satu produk.']);

            $receiptId = DB::table('purchase_receipts')->insertGetId([
                'purchase_order_id' => $id, 'received_by' => $request->user()->id,
                'request_key' => $data['request_key'], 'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($received as [$item, $quantity]) {
                DB::table('stocks')->insertOrIgnore([
                    'outlet_id' => $order->outlet_id, 'product_id' => $item->product_id,
                    'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $stock = DB::table('stocks')->where('outlet_id', $order->outlet_id)->where('product_id', $item->product_id)->lockForUpdate()->first();
                if ($stock->quantity + $quantity > 2147483647) {
                    throw ValidationException::withMessages(['quantities' => 'Stok '.$item->name.' melebihi batas yang diizinkan.']);
                }
                DB::table('stocks')->where('id', $stock->id)->increment('quantity', $quantity);
                DB::table('purchase_order_items')->where('id', $item->id)->increment('received_quantity', $quantity);
                DB::table('purchase_receipt_items')->insert([
                    'purchase_receipt_id' => $receiptId, 'purchase_order_item_id' => $item->id, 'quantity' => $quantity,
                ]);
                DB::table('stock_movements')->insert([
                    'business_id' => $order->business_id, 'outlet_id' => $order->outlet_id,
                    'product_id' => $item->product_id, 'purchase_receipt_id' => $receiptId,
                    'actor_id' => $request->user()->id, 'delta' => $quantity, 'reason' => 'purchase_received',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $incomplete = DB::table('purchase_order_items')->where('purchase_order_id', $id)->whereColumn('received_quantity', '<', 'quantity')->exists();
            DB::table('purchase_orders')->where('id', $id)->update(['status' => $incomplete ? 'partial' : 'received', 'updated_at' => now()]);
        }, 3);
        return redirect()->route('purchases.show', $id)->with('success', 'Penerimaan dicatat dan stok outlet diperbarui.');
    }

    public function cancel(Request $request, int $id)
    {
        $this->canManage($request);
        DB::transaction(function () use ($request, $id) {
            $order = $this->order($request, $id, true);
            if (!in_array($order->status, ['ordered', 'partial'], true)) {
                throw ValidationException::withMessages(['purchase' => 'Pesanan ini sudah selesai atau dibatalkan.']);
            }
            DB::table('purchase_orders')->where('id', $id)->update([
                'status' => 'cancelled', 'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('purchases.show', $id)->with('success', 'Sisa pesanan dibatalkan. Barang yang sudah diterima tetap tercatat.');
    }
}
