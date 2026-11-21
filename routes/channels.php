<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use App\Support\OutletContext;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('sales.{saleId}', function ($user, $saleId) {
    return DB::table('sales')
        ->where('id', (int) $saleId)
        ->where('business_id', $user->business_id)
        ->where('outlet_id', OutletContext::id(request()))
        ->exists();
});
