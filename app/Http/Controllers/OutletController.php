<?php

namespace App\Http\Controllers;

use App\Support\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OutletController extends Controller
{
    private function authorizeOwner(Request $request): void
    {
        abort_unless(RoleAccess::allowsMenu($request->user(), 'outlets'), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeOwner($request);
        $business = $request->user()->business_id;

        return view('outlets', [
            'outlets' => DB::table('outlets as o')
                ->leftJoin('user_outlets as ua', 'ua.outlet_id', '=', 'o.id')
                ->leftJoin('users as u', function ($join) {
                    $join->on('u.id', '=', 'ua.user_id')->where('u.is_active', true);
                })
                ->where('o.business_id', $business)
                ->select('o.id', 'o.name', 'o.code', DB::raw('COUNT(u.id) as user_count'))
                ->groupBy('o.id', 'o.name', 'o.code')
                ->orderBy('o.name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOwner($request);
        $business = $request->user()->business_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('outlets', 'code')->where('business_id', $business)],
        ]);

        DB::table('outlets')->insert(['business_id' => $business, 'name' => $data['name'], 'code' => $data['code'], 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('outlets')->with('success', 'Outlet berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->authorizeOwner($request);
        $business = $request->user()->business_id;
        abort_unless(DB::table('outlets')->where('business_id', $business)->where('id', $id)->exists(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('outlets', 'code')->where('business_id', $business)->ignore($id)],
        ]);

        DB::table('outlets')->where('business_id', $business)->where('id', $id)->update($data + ['updated_at' => now()]);

        return redirect()->route('outlets')->with('success', 'Outlet berhasil diperbarui.');
    }
}
