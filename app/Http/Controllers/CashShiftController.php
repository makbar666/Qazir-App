<?php

namespace App\Http\Controllers;

use App\Services\CashShiftService;
use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashShiftController extends Controller
{
    public function __construct(private CashShiftService $shifts) {}

    private function shift(Request $request, int $id, bool $lock = false): object
    {
        $query = DB::table('cash_shifts')->where('id', $id)->where('business_id', $request->user()->business_id)
            ->where('outlet_id', OutletContext::id($request));
        if (!RoleAccess::allowsAbility($request->user(), 'cash_shifts_manage')) $query->where('cashier_id', $request->user()->id);
        if ($lock) $query->lockForUpdate();
        $shift = $query->first();
        abort_unless($shift, 404);
        return $shift;
    }

    public function index(Request $request)
    {
        $outlet = OutletContext::id($request);
        $canManage = RoleAccess::allowsAbility($request->user(), 'cash_shifts_manage');
        $query = DB::table('cash_shifts as s')->join('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.business_id', $request->user()->business_id)->where('s.outlet_id', $outlet);
        if (!$canManage) $query->where('s.cashier_id', $request->user()->id);
        return view('shifts', [
            'shifts' => $query->select('s.*', 'u.name as cashier_name')->orderByDesc('s.id')->paginate(20),
            'openShift' => $this->shifts->active($request->user(), $outlet),
            'outletName' => DB::table('outlets')->where('id', $outlet)->value('name'),
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ]);
        $id = DB::transaction(function () use ($request, $data) {
            $outlet = OutletContext::id($request);
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            if ($this->shifts->active($request->user(), $outlet)) {
                throw ValidationException::withMessages(['shift' => 'Selesaikan shift yang masih terbuka di outlet ini.']);
            }
            return DB::table('cash_shifts')->insertGetId([
                'business_id' => $request->user()->business_id, 'outlet_id' => $outlet,
                'cashier_id' => $request->user()->id, 'status' => 'open',
                'open_key' => $outlet.':'.$request->user()->id,
                'opening_cash' => $data['opening_cash'], 'opening_note' => $data['opening_note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('shifts.show', $id)->with('success', 'Shift kasir dibuka.');
    }

    public function show(Request $request, int $id)
    {
        $shift = $this->shift($request, $id);
        $shift->cashier_name = DB::table('users')->where('id', $shift->cashier_id)->value('name');
        $entries = DB::table('cash_shift_entries as e')->join('users as u', 'u.id', '=', 'e.actor_id')
            ->where('e.cash_shift_id', $id)->select('e.*', 'u.name as actor_name')->orderByDesc('e.id')->get();
        $sum = $entries->sum('delta');
        return view('shift', [
            'shift' => $shift,
            'entries' => $entries,
            'expected' => $shift->status === 'closed' ? $shift->expected_cash : $shift->opening_cash + $sum,
            'salesTotal' => $entries->where('type', 'sale')->sum('delta'),
            'refundTotal' => -$entries->where('type', 'refund')->sum('delta'),
            'canOperate' => $shift->status === 'open' && ((int) $shift->cashier_id === $request->user()->id || RoleAccess::allowsAbility($request->user(), 'cash_shifts_manage')),
            'outletName' => DB::table('outlets')->where('id', $shift->outlet_id)->value('name'),
        ]);
    }

    public function entry(Request $request, int $id)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['pay_in', 'pay_out'])],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $shift = $this->shift($request, $id, true);
            if ($shift->status !== 'open') throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            DB::table('cash_shift_entries')->insert([
                'cash_shift_id' => $id, 'actor_id' => $request->user()->id,
                'type' => $data['type'], 'delta' => $data['type'] === 'pay_in' ? $data['amount'] : -$data['amount'],
                'note' => $data['note'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('shifts.show', $id)->with('success', 'Pergerakan uang tunai dicatat.');
    }

    public function close(Request $request, int $id)
    {
        $data = $request->validate([
            'actual_cash' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $id, $data) {
            $shift = $this->shift($request, $id, true);
            if ($shift->status !== 'open') throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            if (DB::table('sales')->where('cash_shift_id', $id)->where('payment_method', 'cash')
                ->whereIn('status', ['pending_loyalty', 'pending'])->exists()) {
                throw ValidationException::withMessages(['shift' => 'Masih ada pembayaran tunai yang diproses. Selesaikan sebelum menutup shift.']);
            }
            $expected = $shift->opening_cash + DB::table('cash_shift_entries')->where('cash_shift_id', $id)->sum('delta');
            DB::table('cash_shifts')->where('id', $id)->update([
                'status' => 'closed', 'open_key' => null, 'expected_cash' => $expected,
                'actual_cash' => $data['actual_cash'], 'difference' => $data['actual_cash'] - $expected,
                'closing_note' => $data['closing_note'] ?? null, 'closed_by' => $request->user()->id,
                'closed_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        return redirect()->route('shifts.show', $id)->with('success', 'Shift ditutup dan selisih kas dihitung.');
    }
}
