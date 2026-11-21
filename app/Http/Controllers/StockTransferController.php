<?php

namespace App\Http\Controllers;

use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    private function canManage(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'transfers_manage'), 403);
    }

    private function availableIds(Request $request): array
    {
        return OutletContext::available($request->user())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function transfer(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('stock_transfers')->where('business_id', $request->user()->business_id)->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $transfer = $query->first();
        abort_unless($transfer && (in_array((int) $transfer->source_outlet_id, $this->availableIds($request), true)
            || in_array((int) $transfer->destination_outlet_id, $this->availableIds($request), true)), 404);
        return $transfer;
    }

    public function index(Request $request)
    {
        $ids = $this->availableIds($request);
        $business = $request->user()->business_id;
        $outlet = OutletContext::id($request);
        return view('transfers', [
            'transfers' => DB::table('stock_transfers as t')
                ->join('outlets as source', 'source.id', '=', 't.source_outlet_id')
                ->join('outlets as destination', 'destination.id', '=', 't.destination_outlet_id')
                ->where('t.business_id', $business)
                ->where(fn ($q) => $q->whereIn('t.source_outlet_id', $ids)->orWhereIn('t.destination_outlet_id', $ids))
                ->select('t.*', 'source.name as source_name', 'destination.name as destination_name')
                ->orderByDesc('t.id')->paginate(20),
            'outlets' => OutletContext::available($request->user())->where('id', '!=', $outlet),
            'products' => DB::table('products as p')
                ->join('stocks as s', fn ($join) => $join->on('s.product_id', '=', 'p.id')->where('s.outlet_id', $outlet))
                ->where('p.business_id', $business)->whereNull('p.deleted_at')->where('p.active', true)
                ->where('s.quantity', '>', 0)->select('p.id', 'p.sku', 'p.name', 's.quantity')->orderBy('p.name')->get(),
            'canManage' => RoleAccess::allowsAbility($request->user(), 'transfers_manage'),
            'activeOutletId' => $outlet,
            'sourceOutletName' => DB::table('outlets')->where('id', $outlet)->value('name'),
        ]);
    }

    public function store(Request $request)
    {
        $this->canManage($request);
        $data = $request->validate([
            'destination_outlet_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $source = OutletContext::id($request);
        $destination = (int) $data['destination_outlet_id'];
        if ($source === $destination || !in_array($destination, $this->availableIds($request), true)) {
            throw ValidationException::withMessages(['destination_outlet_id' => 'Pilih outlet tujuan yang tersedia dan berbeda dari outlet asal.']);
        }

        $id = DB::transaction(function () use ($request, $data, $source, $destination) {
            $business = $request->user()->business_id;
            $items = collect($data['items'])->sortBy('product_id')->values();
            $products = DB::table('products')->where('business_id', $business)->whereNull('deleted_at')->where('active', true)
                ->whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
            if ($products->count() !== $items->count()) {
                throw ValidationException::withMessages(['items' => 'Salah satu produk tidak tersedia.']);
            }
            $stocks = DB::table('stocks')->where('outlet_id', $source)->whereIn('product_id', $items->pluck('product_id'))
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            foreach ($items as $item) {
                $stock = $stocks->get($item['product_id']);
                if (!$stock || $stock->quantity < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => 'Stok '.$products[$item['product_id']]->name.' tidak cukup.']);
                }
            }

            $id = DB::table('stock_transfers')->insertGetId([
                'business_id' => $business, 'source_outlet_id' => $source, 'destination_outlet_id' => $destination,
                'created_by' => $request->user()->id, 'status' => 'in_transit', 'note' => $data['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];
                DB::table('stock_transfer_items')->insert(['stock_transfer_id' => $id, 'product_id' => $productId, 'quantity' => $quantity]);
                DB::table('stocks')->where('id', $stocks[$productId]->id)->decrement('quantity', $quantity);
                $this->movement($business, $source, $productId, $id, $request->user()->id, -$quantity, 'transfer_sent');
            }
            return $id;
        }, 3);

        return redirect()->route('transfers.show', $id)->with('success', 'Stok dikirim. Minta outlet tujuan mengonfirmasi penerimaan.');
    }

    public function show(Request $request, int $id)
    {
        $transfer = $this->transfer($request, $id);
        $transfer->source_name = DB::table('outlets')->where('id', $transfer->source_outlet_id)->value('name');
        $transfer->destination_name = DB::table('outlets')->where('id', $transfer->destination_outlet_id)->value('name');
        return view('transfer', [
            'transfer' => $transfer,
            'items' => DB::table('stock_transfer_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
                ->where('i.stock_transfer_id', $id)->select('i.*', 'p.sku', 'p.name')->orderBy('p.name')->get(),
            'canManage' => RoleAccess::allowsAbility($request->user(), 'transfers_manage'),
            'activeOutletId' => OutletContext::id($request),
        ]);
    }

    public function receive(Request $request, int $id)
    {
        $this->canManage($request);
        DB::transaction(function () use ($request, $id) {
            $transfer = $this->transfer($request, $id, true);
            abort_unless((int) $transfer->destination_outlet_id === OutletContext::id($request), 403);
            if ($transfer->status !== 'in_transit') throw ValidationException::withMessages(['transfer' => 'Transfer ini sudah diproses.']);
            $items = DB::table('stock_transfer_items')->where('stock_transfer_id', $id)->orderBy('product_id')->get();
            foreach ($items as $item) {
                DB::table('stocks')->insertOrIgnore([
                    'outlet_id' => $transfer->destination_outlet_id, 'product_id' => $item->product_id,
                    'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('stocks')->where('outlet_id', $transfer->destination_outlet_id)->where('product_id', $item->product_id)
                    ->increment('quantity', $item->quantity);
                $this->movement($transfer->business_id, $transfer->destination_outlet_id, $item->product_id, $id,
                    $request->user()->id, $item->quantity, 'transfer_received');
            }
            DB::table('stock_transfers')->where('id', $id)->update([
                'status' => 'received', 'received_by' => $request->user()->id, 'received_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('transfers.show', $id)->with('success', 'Stok diterima di outlet tujuan.');
    }

    public function cancel(Request $request, int $id)
    {
        $this->canManage($request);
        DB::transaction(function () use ($request, $id) {
            $transfer = $this->transfer($request, $id, true);
            abort_unless((int) $transfer->source_outlet_id === OutletContext::id($request), 403);
            if ($transfer->status !== 'in_transit') throw ValidationException::withMessages(['transfer' => 'Transfer ini sudah diproses.']);
            foreach (DB::table('stock_transfer_items')->where('stock_transfer_id', $id)->orderBy('product_id')->get() as $item) {
                DB::table('stocks')->where('outlet_id', $transfer->source_outlet_id)->where('product_id', $item->product_id)
                    ->increment('quantity', $item->quantity);
                $this->movement($transfer->business_id, $transfer->source_outlet_id, $item->product_id, $id,
                    $request->user()->id, $item->quantity, 'transfer_cancelled');
            }
            DB::table('stock_transfers')->where('id', $id)->update([
                'status' => 'cancelled', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('transfers.show', $id)->with('success', 'Transfer dibatalkan dan stok kembali ke outlet asal.');
    }

    private function movement(int $business, int $outlet, int $product, int $transfer, int $actor, int $delta, string $reason): void
    {
        DB::table('stock_movements')->insert([
            'business_id' => $business, 'outlet_id' => $outlet, 'product_id' => $product,
            'stock_transfer_id' => $transfer, 'actor_id' => $actor, 'delta' => $delta, 'reason' => $reason,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
