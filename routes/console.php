<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:close-inactive-tickets')->everyTenMinutes()->withoutOverlapping();
Schedule::command('app:drain-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('app:sync-google-contacts')->everyTenMinutes()->withoutOverlapping();
Schedule::command('app:purge-media')->dailyAt('03:30')->withoutOverlapping();
