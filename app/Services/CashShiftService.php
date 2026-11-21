<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashShiftService
{
    public function active(User $user, int $outletId, bool $lock = false): ?object
    {
        $query = DB::table('cash_shifts')->where('business_id', $user->business_id)
            ->where('outlet_id', $outletId)->where('cashier_id', $user->id)->where('status', 'open');
        if ($lock) $query->lockForUpdate();
        return $query->first();
    }

    public function enforced(int $outletId): bool
    {
        return DB::table('cash_shifts')->where('outlet_id', $outletId)->exists();
    }

    public function forSale(User $user, int $outletId, bool $cash): ?object
    {
        $shift = $this->active($user, $outletId, true);
        if ($cash && !$shift && $this->enforced($outletId)) {
            throw ValidationException::withMessages(['payment_method' => 'Buka shift kasir di outlet ini sebelum menerima pembayaran tunai.']);
        }
        return $shift;
    }

    public function recordSale(int $saleId): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();
        if (!$sale || $sale->status !== 'paid' || $sale->payment_method !== 'cash' || !$sale->cash_shift_id) return;
        $this->record($sale->cash_shift_id, $saleId, $sale->cashier_id, 'sale', (int) $sale->total, $sale->number);
    }

    public function recordRefund(object $sale, int $actorId, ?int $shiftId): void
    {
        if ($sale->payment_method !== 'cash' || !$shiftId) return;
        $this->record($shiftId, $sale->id, $actorId, 'refund', -(int) $sale->total, 'Refund '.$sale->number);
    }

    private function record(int $shiftId, int $saleId, int $actorId, string $type, int $delta, string $note): void
    {
        $shift = DB::table('cash_shifts')->where('id', $shiftId)->lockForUpdate()->first();
        if (!$shift || $shift->status !== 'open') {
            throw ValidationException::withMessages(['shift' => 'Shift kasir sudah ditutup. Buka shift baru sebelum memproses uang tunai.']);
        }
        DB::table('cash_shift_entries')->insertOrIgnore([
            'cash_shift_id' => $shiftId, 'sale_id' => $saleId, 'actor_id' => $actorId,
            'type' => $type, 'delta' => $delta, 'note' => $note,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
