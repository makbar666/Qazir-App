<?php

namespace App\Http\Controllers;

use App\Services\DailyReconciliationService;
use App\Services\MidtransService;
use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyReconciliationController extends Controller
{
    public function __construct(private DailyReconciliationService $reconciliation) {}

    private function date(Request $request): string
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->reconciliation->today();
        if ($date > $this->reconciliation->today()) {
            throw ValidationException::withMessages(['date' => 'Tanggal rekonsiliasi tidak boleh di masa depan.']);
        }
        return $date;
    }

    private function manage(Request $request): void
    {
        abort_unless(RoleAccess::allowsAbility($request->user(), 'reconciliation_manage'), 403);
    }

    private function sameOutlet(Request $request): int
    {
        $outletId = OutletContext::id($request);
        if ($request->filled('outlet_id') && (int) $request->input('outlet_id') !== $outletId) {
            throw ValidationException::withMessages(['outlet_id' => 'Outlet aktif berubah. Muat ulang halaman rekonsiliasi.']);
        }
        return $outletId;
    }

    public function index(Request $request)
    {
        $this->manage($request);
        $date = $this->date($request);
        $businessId = $request->user()->business_id;
        $outletId = OutletContext::id($request);
        $summary = $this->reconciliation->summary($businessId, $outletId, $date);
        $review = DB::table('daily_reconciliation_reviews as r')->join('users as u', 'u.id', '=', 'r.reviewed_by')
            ->where('r.business_id', $businessId)->where('r.outlet_id', $outletId)->where('r.business_date', $date)
            ->select('r.*', 'u.name as reviewer_name')->orderByDesc('r.id')->first();
        return view('reconciliation', [
            'summary' => $summary, 'review' => $review, 'outletId' => $outletId,
            'outletName' => DB::table('outlets')->where('business_id', $businessId)->where('id', $outletId)->value('name'),
        ]);
    }

    public function check(Request $request, MidtransService $midtrans)
    {
        $this->manage($request);
        $date = $this->date($request);
        $result = $this->reconciliation->checkQris($request->user()->business_id, $this->sameOutlet($request), $date, $midtrans);
        return redirect()->route('reconciliation', ['date' => $date])->with('success',
            $result['checked'] ? $result['success'].' dari '.$result['checked'].' QRIS berhasil diperiksa. Total QRIS Midtrans terkait tanggal ini: '.$result['total'].'.' : 'Tidak ada QRIS Midtrans untuk diperiksa.');
    }

    public function review(Request $request)
    {
        $this->manage($request);
        $date = $this->date($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $businessId = $request->user()->business_id;
        $outletId = $this->sameOutlet($request);
        $summary = $this->reconciliation->summary($businessId, $outletId, $date);
        if ($summary['blocking']) throw ValidationException::withMessages(['reconciliation' => 'Selesaikan transaksi, shift, dan pemeriksaan QRIS yang belum cocok sebelum mengesahkan.']);
        if ($summary['warnings'] && mb_strlen(trim($data['note'] ?? '')) < 10) {
            throw ValidationException::withMessages(['note' => 'Jelaskan selisih kas dalam catatan minimal 10 karakter.']);
        }
        $latest = DB::table('daily_reconciliation_reviews')->where('business_id', $businessId)
            ->where('outlet_id', $outletId)->where('business_date', $date)->orderByDesc('id')->first();
        if ($latest?->snapshot_hash === $summary['hash']) {
            return redirect()->route('reconciliation', ['date' => $date])->with('success', 'Rekonsiliasi ini sudah disahkan.');
        }
        DB::table('daily_reconciliation_reviews')->insert([
            'business_id' => $businessId, 'outlet_id' => $outletId, 'business_date' => $date,
            'reviewed_by' => $request->user()->id, 'snapshot_hash' => $summary['hash'],
            'snapshot' => json_encode([
                'sales_count' => $summary['salesCount'], 'gross' => $summary['gross'],
                'refund_total' => $summary['refundTotal'], 'cash_ledger_difference' => $summary['cashLedgerDifference'],
                'shift_difference' => $summary['shiftDifference'], 'qris_count' => $summary['qrisRows']->count(),
                'warnings' => $summary['warnings'],
            ], JSON_THROW_ON_ERROR),
            'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return redirect()->route('reconciliation', ['date' => $date])->with('success', 'Rekonsiliasi harian disahkan.');
    }
}
