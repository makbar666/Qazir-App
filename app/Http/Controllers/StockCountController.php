<?php

namespace App\Http\Controllers;

use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    private function canCount(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'stock_counts_manage'), 403);
    }

    private function canApprove(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'stock_counts_approve'), 403);
    }

    private function count(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('stock_counts')->where('id', $id)->where('business_id', $request->user()->business_id)
            ->where('outlet_id', OutletContext::id($request));
        if ($lock) $query->lockForUpdate();
        $count = $query->first();
        abort_unless($count, 404);
        return $count;
    }

    public function index(Request $request)
    {
        $outlet = OutletContext::id($request);
        $query = DB::table('stock_counts as c')->join('users as u', 'u.id', '=', 'c.started_by')
            ->where('c.business_id', $request->user()->business_id)->where('c.outlet_id', $outlet);
        return view('stock-counts', [
            'counts' => (clone $query)->select('c.*', 'u.name as starter_name')->orderByDesc('c.id')->paginate(20),
            'openCount' => (clone $query)->whereIn('c.status', ['draft', 'submitted'])->select('c.id', 'c.status')->first(),
            'canCount' => RoleAccess::allowsAbility($request->user(), 'stock_counts_manage'),
            'outletName' => DB::table('outlets')->where('id', $outlet)->value('name'),
        ]);
    }

    public function store(Request $request)
    {
        $this->canCount($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $id = DB::transaction(function () use ($request, $data) {
            $outlet = OutletContext::id($request);
            $business = $request->user()->business_id;
            DB::table('outlets')->where('id', $outlet)->lockForUpdate()->first();
            if (DB::table('stock_counts')->where('outlet_id', $outlet)->whereIn('status', ['draft', 'submitted'])->exists()) {
                throw ValidationException::withMessages(['count' => 'Selesaikan atau batalkan opname yang masih terbuka di outlet ini.']);
            }
            $watermark = DB::table('stock_movements')->max('id') ?? 0;
            $products = DB::table('products')->where('business_id', $business)->whereNull('deleted_at')
                ->orderBy('id')->get(['id']);
            if ($products->isEmpty()) throw ValidationException::withMessages(['count' => 'Belum ada produk untuk dihitung.']);
            $stocks = DB::table('stocks')->where('outlet_id', $outlet)->whereIn('product_id', $products->pluck('id'))
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $id = DB::table('stock_counts')->insertGetId([
                'business_id' => $business, 'outlet_id' => $outlet, 'started_by' => $request->user()->id,
                'status' => 'draft', 'movement_watermark' => $watermark, 'note' => $data['note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($products as $product) {
                DB::table('stock_count_items')->insert([
                    'stock_count_id' => $id, 'product_id' => $product->id,
                    'expected_quantity' => $stocks->get($product->id)?->quantity ?? 0,
                ]);
            }
            return $id;
        }, 3);
        return redirect()->route('stock_counts.show', $id)->with('success', 'Opname dimulai. Isi jumlah fisik setiap produk.');
    }

    public function show(Request $request, int $id)
    {
        $count = $this->count($request, $id);
        $items = DB::table('stock_count_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.stock_count_id', $id)->select('i.*', 'p.sku', 'p.name')->orderBy('p.name')->get();
        return view('stock-count', [
            'count' => $count,
            'items' => $items,
            'counted' => $items->whereNotNull('counted_quantity')->count(),
            'canCount' => RoleAccess::allowsAbility($request->user(), 'stock_counts_manage'),
            'canApprove' => RoleAccess::allowsAbility($request->user(), 'stock_counts_approve'),
            'outletName' => DB::table('outlets')->where('id', $count->outlet_id)->value('name'),
            'stockChanged' => $count->status === 'submitted' && DB::table('stock_movements')
                ->where('outlet_id', $count->outlet_id)->where('id', '>', $count->movement_watermark)->exists(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $this->canCount($request);
        $data = $request->validate([
            'counts' => ['required', 'array', 'min:1'],
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $count = $this->count($request, $id, true);
            if ($count->status !== 'draft') throw ValidationException::withMessages(['count' => 'Opname ini tidak dapat diubah.']);
            $ids = array_keys($data['counts']);
            $valid = DB::table('stock_count_items')->where('stock_count_id', $id)->whereIn('product_id', $ids)->count();
            if ($valid !== count($ids)) throw ValidationException::withMessages(['counts' => 'Produk tidak termasuk dalam opname ini.']);
            foreach ($data['counts'] as $productId => $quantity) {
                DB::table('stock_count_items')->where('stock_count_id', $id)->where('product_id', $productId)
                    ->update(['counted_quantity' => $quantity]);
            }
            DB::table('stock_counts')->where('id', $id)->update(['updated_at' => now()]);
        }, 3);
        return redirect()->route('stock_counts.show', $id)->with('success', 'Hasil hitung disimpan.');
    }

    public function submit(Request $request, int $id)
    {
        $this->canCount($request);
        DB::transaction(function () use ($request, $id) {
            $count = $this->count($request, $id, true);
            if ($count->status !== 'draft') throw ValidationException::withMessages(['count' => 'Opname ini sudah diajukan.']);
            if (DB::table('stock_count_items')->where('stock_count_id', $id)->whereNull('counted_quantity')->exists()) {
                throw ValidationException::withMessages(['count' => 'Isi jumlah fisik seluruh produk, termasuk yang jumlahnya nol.']);
            }
            DB::table('stock_counts')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by' => $request->user()->id,
                'submitted_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('stock_counts.show', $id)->with('success', 'Hasil opname diajukan untuk persetujuan.');
    }

    public function approve(Request $request, int $id)
    {
        $this->canApprove($request);
        DB::transaction(function () use ($request, $id) {
            $count = $this->count($request, $id, true);
            if ($count->status !== 'submitted') throw ValidationException::withMessages(['count' => 'Opname ini belum diajukan atau sudah diproses.']);
            $items = DB::table('stock_count_items')->where('stock_count_id', $id)->orderBy('product_id')->get();
            foreach ($items as $item) {
                DB::table('stocks')->insertOrIgnore([
                    'outlet_id' => $count->outlet_id, 'product_id' => $item->product_id,
                    'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $stocks = DB::table('stocks')->where('outlet_id', $count->outlet_id)->whereIn('product_id', $items->pluck('product_id'))
                ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            if (DB::table('stock_movements')->where('outlet_id', $count->outlet_id)->where('id', '>', $count->movement_watermark)
                ->orderBy('id')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['count' => 'Stok outlet berubah sejak opname dimulai. Batalkan opname ini lalu mulai penghitungan baru.']);
            }
            foreach ($items as $item) {
                $current = $stocks->get($item->product_id)?->quantity ?? 0;
                if ($current !== $item->expected_quantity) {
                    throw ValidationException::withMessages(['count' => 'Stok outlet berubah sejak opname dimulai. Batalkan opname ini lalu mulai penghitungan baru.']);
                }
            }
            foreach ($items as $item) {
                $delta = $item->counted_quantity - $item->expected_quantity;
                if ($delta === 0) continue;
                DB::table('stocks')->where('outlet_id', $count->outlet_id)->where('product_id', $item->product_id)
                    ->update(['quantity' => $item->counted_quantity, 'updated_at' => now()]);
                DB::table('stock_movements')->insert([
                    'business_id' => $count->business_id, 'outlet_id' => $count->outlet_id,
                    'product_id' => $item->product_id, 'stock_count_id' => $id,
                    'actor_id' => $request->user()->id, 'delta' => $delta, 'reason' => 'stock_count',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('stock_counts')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $request->user()->id,
                'approved_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('stock_counts.show', $id)->with('success', 'Opname disetujui dan stok outlet diperbarui.');
    }

    public function cancel(Request $request, int $id)
    {
        $this->canCount($request);
        DB::transaction(function () use ($request, $id) {
            $count = $this->count($request, $id, true);
            if (!in_array($count->status, ['draft', 'submitted'], true)) {
                throw ValidationException::withMessages(['count' => 'Opname ini sudah selesai.']);
            }
            if ($count->status === 'submitted' && !RoleAccess::allowsAbility($request->user(), 'stock_counts_approve')) abort(403);
            if ($count->status === 'draft' && (int) $count->started_by !== $request->user()->id
                && !RoleAccess::allowsAbility($request->user(), 'stock_counts_approve')) abort(403);
            DB::table('stock_counts')->where('id', $id)->update([
                'status' => 'cancelled', 'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('stock_counts')->with('success', 'Opname dibatalkan. Stok tidak berubah.');
    }
}
