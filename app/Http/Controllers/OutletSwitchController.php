<?php

namespace App\Http\Controllers;

use App\Support\OutletContext;
use App\Support\RoleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OutletSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'integer']]);
        $outlet = OutletContext::available($request->user())->firstWhere('id', (int) $data['outlet_id']);

        if (!$outlet) throw ValidationException::withMessages(['outlet_id' => 'Outlet tidak tersedia untuk akun ini.']);

        $request->session()->put('active_outlet_id', (int) $outlet->id);

        return redirect()->route(RoleAccess::homeRoute($request->user()))->with('success', 'Outlet aktif: '.$outlet->name.'.');
    }
}
