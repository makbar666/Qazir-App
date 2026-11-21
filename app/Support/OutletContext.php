<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OutletContext
{
    public static function available(User $user): Collection
    {
        $query = DB::table('outlets')->where('business_id', $user->business_id);

        if ($user->role !== 'owner') {
            $query->whereIn('id', DB::table('user_outlets')->where('user_id', $user->id)->select('outlet_id'));
        }

        return $query->orderBy('name')->get(['id', 'name', 'code']);
    }

    public static function id(Request $request): int
    {
        $outlets = self::available($request->user());
        $selected = (int) $request->session()->get('active_outlet_id');
        if ($outlets->contains('id', $selected)) return $selected;

        $default = (int) $request->user()->outlet_id;
        if ($outlets->contains('id', $default)) return $default;

        abort_unless($outlets->isNotEmpty(), 403, 'Akun belum diberi akses ke outlet.');

        return (int) $outlets->first()->id;
    }
}
