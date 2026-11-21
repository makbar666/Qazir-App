<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DailyReconciliationService
{
    public function timezone(): string
    {
        return config('reconciliation.timezone', 'Asia/Jakarta');
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    private function bounds(string $date): array
    {
        $start = CarbonImmutable::parse($date, $this->timezone())->startOfDay();
        return [$start->utc(), $start->addDay()->utc()];
    }

    private function during($query, string $column, array $bounds)
    {
        return $query->where($column, '>=', $bounds[0])->where($column, '<', $bounds[1]);
    }

    private function qrisSales(int $businessId, int $outletId, array $bounds)
    {
        return DB::table('sales')->where('business_id', $businessId)->where('outlet_id', $outletId)
            ->where('payment_method', 'qris')->whereNotNull('midtrans_status')
            ->where(function ($query) use ($bounds) {
                $query->where(fn ($q) => $this->during($q, 'paid_at', $bounds))
                    ->orWhere(fn ($q) => $this->during($q, 'refunded_at', $bounds))
                    ->orWhere(fn ($q) => $this->during($q, 'created_at', $bounds)->whereIn('status', ['pending', 'pending_loyalty']))
                    ->orWhereIn('id', function ($refundQuery) use ($bounds) {
                        $refundQuery->select('sale_id')->from('midtrans_refunds')
                            ->where('created_at', '<', $bounds[1])
                            ->where(fn ($q) => $q->where('created_at', '>=', $bounds[0])
                                ->orWhereIn('status', ['preparing', 'pending', 'request_failed', 'review']));
                    });
            })->orderBy('id')->get();
    }

    public function summary(int $businessId, int $outletId, string $date): array
    {
        $bounds = $this->bounds($date);
        $sales = $this->during(DB::table('sales')->where('business_id', $businessId)->where('outlet_id', $outletId), 'paid_at', $bounds)
            ->whereIn('status', ['paid', 'refunded'])->orderBy('id')->get();
        $refunds = $this->during(DB::table('sales')->where('business_id', $businessId)->where('outlet_id', $outletId), 'refunded_at', $bounds)
            ->where('status', 'refunded')->orderBy('id')->get();
        $pending = DB::table('sales')->where('business_id', $businessId)->where('outlet_id', $outletId)
            ->where('created_at', '<', $bounds[1])
            ->whereIn('status', ['pending', 'pending_loyalty'])->orderBy('id')->get();
        $shifts = DB::table('cash_shifts as s')->join('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.business_id', $businessId)->where('s.outlet_id', $outletId)
            ->where('s.created_at', '<', $bounds[1])
            ->where(fn ($q) => $q->where('s.closed_at', '>=', $bounds[0])->orWhereNull('s.closed_at'))
            ->select('s.*', 'u.name as cashier_name')->orderBy('s.id')->get();
        $entries = $this->during(DB::table('cash_shift_entries as e')->join('cash_shifts as s', 's.id', '=', 'e.cash_shift_id')
            ->where('s.business_id', $businessId)->where('s.outlet_id', $outletId), 'e.created_at', $bounds)
            ->select('e.id', 'e.type', 'e.delta', 'e.sale_id')->orderBy('e.id')->get();
        $qrisSales = $this->qrisSales($businessId, $outletId, $bounds);
        $checks = DB::table('qris_reconciliation_checks')->where('business_id', $businessId)->where('outlet_id', $outletId)
            ->whereIn('sale_id', $qrisSales->pluck('id'))->get()->keyBy('sale_id');
        $midtransRefunds = DB::table('midtrans_refunds')->where('business_id', $businessId)->where('outlet_id', $outletId)
            ->whereIn('sale_id', $qrisSales->pluck('id'))->get()->keyBy('sale_id');
        $checkAfter = $date === $this->today() ? $bounds[0] : $bounds[1];
        $qrisRows = $qrisSales->map(function ($sale) use ($checks, $checkAfter, $midtransRefunds) {
            $check = $checks->get($sale->id);
            $refund = $midtransRefunds->get($sale->id);
            $problem = null;
            if (!$check || CarbonImmutable::parse($check->checked_at)->lt($checkAfter)) $problem = 'Perlu diperiksa ke Midtrans';
            elseif ($check->error) $problem = 'Gagal diperiksa: '.$check->error;
            elseif ((int) $check->remote_amount !== (int) $sale->total) $problem = 'Nominal Midtrans berbeda';
            elseif (in_array($sale->midtrans_status, ['refund_review', 'payment_review'], true)) $problem = 'Status perlu pemeriksaan manual';
            elseif ($refund && $refund->status !== 'confirmed') $problem = 'Permintaan refund '.$refund->status.' belum selesai';
            elseif ($sale->midtrans_status === 'refund_pending') $problem = 'Refund menunggu konfirmasi bank';
            elseif ($sale->status === 'paid' && $check->remote_status !== 'settlement') $problem = 'Status POS dan Midtrans berbeda';
            elseif ($sale->status === 'refunded' && $check->remote_status !== 'refund') $problem = 'Status refund POS dan Midtrans berbeda';
            elseif ($sale->status === 'pending') $problem = 'Pembayaran belum selesai';
            elseif (!in_array($sale->status, ['paid', 'refunded', 'cancelled'], true)) $problem = 'Transaksi belum selesai';
            return (object) ['sale' => $sale, 'check' => $check, 'refund' => $refund, 'problem' => $problem];
        });

        $cashSales = (int) $sales->where('payment_method', 'cash')->sum('total');
        $cashRefunds = (int) $refunds->where('payment_method', 'cash')->sum('total');
        $cashLedger = (int) $entries->whereIn('type', ['sale', 'refund'])->sum('delta');
        $cashLedgerDifference = $cashSales - $cashRefunds - $cashLedger;
        $closedToday = $shifts->filter(fn ($s) => $s->closed_at
            && CarbonImmutable::parse($s->closed_at)->gte($bounds[0])
            && CarbonImmutable::parse($s->closed_at)->lt($bounds[1]));
        $shiftDifference = (int) $closedToday->sum('difference');
        $blocking = [];
        if ($pending->isNotEmpty()) $blocking[] = $pending->count().' transaksi masih menunggu pembayaran/reservasi.';
        if ($shifts->where('status', 'open')->isNotEmpty()) $blocking[] = 'Masih ada shift kasir yang terbuka.';
        if ($qrisRows->contains(fn ($row) => $row->problem !== null)) $blocking[] = 'Status QRIS belum seluruhnya cocok dengan Midtrans.';
        $warnings = [];
        if ($cashLedgerDifference !== 0) $warnings[] = 'Penjualan tunai bersih berbeda dari catatan kas shift sebesar Rp'.number_format(abs($cashLedgerDifference), 0, ',', '.').'.';
        if ($shiftDifference !== 0) $warnings[] = 'Jumlah selisih uang fisik dari shift yang ditutup: Rp'.number_format($shiftDifference, 0, ',', '.').'.';
        $crossDayCount = $shifts->filter(fn ($s) => $s->closed_at
            && CarbonImmutable::parse($s->created_at)->timezone($this->timezone())->toDateString()
                !== CarbonImmutable::parse($s->closed_at)->timezone($this->timezone())->toDateString())->count();
        if ($crossDayCount) $warnings[] = $crossDayCount.' shift melewati pergantian hari. Selisih uang fisiknya dicatat pada hari shift ditutup.';
        $manualQrisCount = $sales->where('payment_method', 'qris')->filter(fn ($s) => !$s->midtrans_status)->count();
        $manualQrisRefundCount = $refunds->where('payment_method', 'qris')->filter(fn ($s) => !$s->midtrans_status)->count();
        if ($manualQrisCount || $manualQrisRefundCount) {
            $warnings[] = $manualQrisCount.' pembayaran dan '.$manualQrisRefundCount.' refund QRIS manual perlu dicocokkan dengan mutasi penyedia QRIS di luar aplikasi.';
        }

        $fingerprint = [
            'sales' => $sales->map(fn ($s) => [$s->id, $s->status, $s->payment_method, $s->total, $s->paid_at, $s->refunded_at, $s->midtrans_status])->all(),
            'refunds' => $refunds->map(fn ($s) => [$s->id, $s->total, $s->refunded_at, $s->midtrans_status])->all(),
            'pending' => $pending->map(fn ($s) => [$s->id, $s->status, $s->total])->all(),
            'shifts' => $shifts->map(fn ($s) => [$s->id, $s->status, $s->opening_cash, $s->expected_cash, $s->actual_cash, $s->difference, $s->closed_at])->all(),
            'entries' => $entries->map(fn ($e) => [$e->id, $e->type, $e->delta, $e->sale_id])->all(),
            'qris' => $qrisRows->map(fn ($row) => [$row->sale->id, $row->check?->remote_status, $row->check?->remote_amount, $row->check?->error, $row->refund?->status, $row->problem])->all(),
        ];
        return [
            'date' => $date, 'timezone' => $this->timezone(), 'sales' => $sales, 'refunds' => $refunds,
            'pending' => $pending, 'shifts' => $shifts, 'qrisRows' => $qrisRows,
            'salesCount' => $sales->count(), 'gross' => (int) $sales->sum('total'),
            'refundTotal' => (int) $refunds->sum('total'),
            'cashSales' => $cashSales, 'cashRefunds' => $cashRefunds, 'cashLedger' => $cashLedger,
            'cashLedgerDifference' => $cashLedgerDifference, 'shiftDifference' => $shiftDifference,
            'qrisGross' => (int) $sales->where('payment_method', 'qris')->sum('total'),
            'qrisRefunds' => (int) $refunds->where('payment_method', 'qris')->sum('total'),
            'manualQrisCount' => $manualQrisCount,
            'manualQrisRefundCount' => $manualQrisRefundCount,
            'blocking' => $blocking, 'warnings' => $warnings,
            'hash' => hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)),
        ];
    }

    public function checkQris(int $businessId, int $outletId, string $date, MidtransService $midtrans): array
    {
        $sales = $this->qrisSales($businessId, $outletId, $this->bounds($date));
        $checks = DB::table('qris_reconciliation_checks')->where('business_id', $businessId)->where('outlet_id', $outletId)
            ->whereIn('sale_id', $sales->pluck('id'))->get()->keyBy('sale_id');
        $ordered = $sales->sortBy(fn ($sale) => $checks->get($sale->id)?->checked_at ?? '0000-00-00')->take(10);
        $success = 0;
        foreach ($ordered as $sale) {
            $remoteStatus = null;
            $remoteAmount = null;
            $error = null;
            try {
                $data = $midtrans->status($sale->id);
                $remoteStatus = (string) ($data['transaction_status'] ?? '');
                $remoteAmount = (int) ($data['gross_amount'] ?? 0);
                $success++;
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 500);
            }
            DB::table('qris_reconciliation_checks')->updateOrInsert(['sale_id' => $sale->id], [
                'business_id' => $businessId, 'outlet_id' => $outletId,
                'remote_status' => $remoteStatus, 'remote_amount' => $remoteAmount,
                'error' => $error, 'checked_at' => now(),
                'created_at' => $checks->get($sale->id)?->created_at ?? now(), 'updated_at' => now(),
            ]);
        }
        return ['checked' => $ordered->count(), 'success' => $success, 'total' => $sales->count()];
    }
}
