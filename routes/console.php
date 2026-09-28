<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('revenue:allocate')->dailyAt('00:30')->withoutOverlapping()->onOneServer();
Schedule::command('payouts:run')->weeklyOn(1, '03:00')->withoutOverlapping()->onOneServer();
Schedule::command('payouts:reconcile')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
