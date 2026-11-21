<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoleAccess
{
    public const MENUS = [
        'dashboard' => 'Ringkasan',
        'checkout' => 'Kasir',
        'shifts' => 'Shift kasir',
        'products' => 'Produk & stok',
        'transfers' => 'Transfer stok',
        'purchases' => 'Pembelian',
        'stock_alerts' => 'Stok menipis',
        'stock_counts' => 'Stok opname',
        'sales' => 'Penjualan',
        'report' => 'Laporan',
        'reconciliation' => 'Rekonsiliasi harian',
        'integration' => 'Integrasi Tanda',
        'midtrans' => 'QRIS Midtrans',
        'outlets' => 'Outlet',
    ];

    public const ABILITIES = [
        'products_manage' => 'Kelola produk dan stok',
        'transfers_manage' => 'Kirim dan terima transfer stok',
        'purchases_manage' => 'Kelola pemasok dan pembelian',
        'stock_alerts_manage' => 'Atur batas stok dan email peringatan',
        'stock_counts_manage' => 'Hitung stok outlet',
        'stock_counts_approve' => 'Setujui koreksi stok opname',
        'cash_shifts_manage' => 'Kelola seluruh shift kasir',
        'sales_refund' => 'Catat refund penjualan',
        'reconciliation_manage' => 'Periksa QRIS dan sahkan rekonsiliasi',
    ];

    public const DEFAULTS = [
        'owner' => ['Owner', ['dashboard', 'checkout', 'shifts', 'products', 'transfers', 'purchases', 'stock_alerts', 'stock_counts', 'sales', 'report', 'reconciliation', 'integration', 'midtrans', 'outlets'], ['products_manage', 'transfers_manage', 'purchases_manage', 'stock_alerts_manage', 'stock_counts_manage', 'stock_counts_approve', 'cash_shifts_manage', 'sales_refund', 'reconciliation_manage']],
        'manager' => ['Manager', ['dashboard', 'checkout', 'shifts', 'products', 'transfers', 'purchases', 'stock_alerts', 'stock_counts', 'sales', 'report', 'reconciliation'], ['products_manage', 'transfers_manage', 'purchases_manage', 'stock_alerts_manage', 'stock_counts_manage', 'stock_counts_approve', 'cash_shifts_manage', 'sales_refund', 'reconciliation_manage']],
        'cashier' => ['Kasir', ['dashboard', 'checkout', 'shifts', 'products', 'stock_counts', 'sales'], ['stock_counts_manage']],
    ];

    public static function seedDefaults(int $businessId): void
    {
        foreach (self::DEFAULTS as $key => [$name, $menus, $abilities]) {
            DB::table('roles')->insertOrIgnore([
                'business_id' => $businessId, 'key' => $key, 'name' => $name,
                'menus' => json_encode($menus), 'abilities' => json_encode($abilities),
                'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function definition(User $user): ?object
    {
        $role = DB::table('roles')->where('business_id', $user->business_id)->where('key', $user->role)->first();
        if ($role) return $role;

        $default = self::DEFAULTS[$user->role] ?? null;
        return $default ? (object) ['name' => $default[0], 'menus' => json_encode($default[1]), 'abilities' => json_encode($default[2])] : null;
    }

    public static function allowsMenu(User $user, string $key): bool
    {
        if ($user->role === 'owner') return true;
        $role = self::definition($user);
        return $role && in_array($key, json_decode($role->menus, true) ?: [], true);
    }

    public static function allowsAbility(User $user, string $key): bool
    {
        if ($user->role === 'owner') return true;
        $role = self::definition($user);
        return $role && in_array($key, json_decode($role->abilities, true) ?: [], true);
    }

    public static function homeRoute(User $user): string
    {
        foreach (array_keys(self::MENUS) as $key) {
            if (self::allowsMenu($user, $key)) return $key === 'midtrans' ? 'midtrans.settings' : $key;
        }

        abort(403, 'Peran belum memiliki akses menu.');
    }
}
