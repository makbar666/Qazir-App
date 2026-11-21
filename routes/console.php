<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('loyalty:sync')->everyMinute()->withoutOverlapping();
Schedule::command('stock:alert-low')->dailyAt(config('stock-alerts.daily_time'))
    ->timezone(config('stock-alerts.timezone'))->withoutOverlapping();
if (app()->environment('local')) {
    Schedule::command('midtrans:reconcile')->everyTenSeconds()->withoutOverlapping();
} else {
    Schedule::command('midtrans:reconcile')->everyMinute()->withoutOverlapping();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
