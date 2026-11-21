<?php

namespace App\Http\Controllers;

use App\Services\StockAlertService;
use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StockAlertController extends Controller
{
    private function canManage(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'stock_alerts_manage'), 403);
    }

    public function index(Request $request, StockAlertService $alerts)
    {
        $outlet = OutletContext::id($request);
        $business = $request->user()->business_id;
        $products = DB::table('products as p')
            ->leftJoin('stocks as s', fn ($join) => $join->on('s.product_id', '=', 'p.id')->where('s.outlet_id', $outlet))
            ->leftJoin('stock_alert_rules as r', fn ($join) => $join->on('r.product_id', '=', 'p.id')->where('r.outlet_id', $outlet))
            ->where('p.business_id', $business)->where('p.active', true)->whereNull('p.deleted_at');
        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $products->where(fn ($query) => $query->where('p.name', 'like', '%'.$search.'%')->orWhere('p.sku', 'like', '%'.$search.'%'));
        }
        return view('stock-alerts', [
            'lowProducts' => $alerts->lowForOutlet($business, $outlet),
            'products' => $products->select('p.id', 'p.name', 'p.sku', DB::raw('COALESCE(s.quantity, 0) as quantity'),
                'r.minimum_quantity', 'r.target_quantity')->orderBy('p.name')->paginate(20)->withQueryString(),
            'settings' => DB::table('stock_alert_settings')->where('outlet_id', $outlet)->where('business_id', $business)->first(),
            'outletName' => DB::table('outlets')->where('id', $outlet)->value('name'),
            'canManage' => RoleAccess::allowsAbility($request->user(), 'stock_alerts_manage'),
            'search' => $request->query('q', ''),
            'mailDriver' => config('mail.default'),
            'lastDelivery' => DB::table('stock_alert_deliveries')->where('outlet_id', $outlet)->orderByDesc('sent_at')->first(),
        ]);
    }

    public function saveRule(Request $request, int $product)
    {
        $this->canManage($request);
        $data = $request->validate([
            'minimum_quantity' => ['nullable', 'integer', 'min:0', 'max:2147483646'],
            'target_quantity' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ]);
        $business = $request->user()->business_id;
        abort_unless(DB::table('products')->where('id', $product)->where('business_id', $business)
            ->where('active', true)->whereNull('deleted_at')->exists(), 404);
        $outlet = OutletContext::id($request);
        if (!isset($data['minimum_quantity'])) {
            DB::table('stock_alert_rules')->where('outlet_id', $outlet)->where('product_id', $product)->delete();
            return back()->with('success', 'Peringatan stok produk dinonaktifkan.');
        }
        if (!isset($data['target_quantity']) || $data['target_quantity'] <= $data['minimum_quantity']) {
            throw ValidationException::withMessages(['target_quantity' => 'Target stok harus lebih besar dari batas minimum.']);
        }
        DB::table('stock_alert_rules')->updateOrInsert(
            ['outlet_id' => $outlet, 'product_id' => $product],
            ['business_id' => $business, 'minimum_quantity' => $data['minimum_quantity'],
                'target_quantity' => $data['target_quantity'], 'updated_at' => now()]
        );
        return back()->with('success', 'Batas stok outlet diperbarui.');
    }

    public function saveEmail(Request $request)
    {
        $this->canManage($request);
        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'recipients' => ['nullable', 'string', 'max:2000'],
        ]);
        $addresses = collect(preg_split('/[,;\r\n]+/', $data['recipients'] ?? ''))
            ->map(fn ($address) => mb_strtolower(trim($address)))->filter()->unique()->values();
        if ($addresses->count() > 10) throw ValidationException::withMessages(['recipients' => 'Maksimal 10 alamat email per outlet.']);
        foreach ($addresses as $address) {
            if (Validator::make(['email' => $address], ['email' => ['required', 'email', 'max:255']])->fails()) {
                throw ValidationException::withMessages(['recipients' => 'Ada alamat email yang tidak valid.']);
            }
        }
        if ($data['email_enabled'] && $addresses->isEmpty()) {
            throw ValidationException::withMessages(['recipients' => 'Isi minimal satu alamat email untuk mengaktifkan notifikasi.']);
        }
        $outlet = OutletContext::id($request);
        DB::table('stock_alert_settings')->updateOrInsert(
            ['outlet_id' => $outlet],
            ['business_id' => $request->user()->business_id, 'email_enabled' => $data['email_enabled'],
                'recipient_emails' => json_encode($addresses->all()), 'updated_at' => now()]
        );
        return back()->with('success', 'Pengaturan email stok diperbarui.');
    }
}
