<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Promotional pushes: run every minute when cron calls `php artisan schedule:run`
Schedule::command('notifications:send-scheduled')->everyMinute();
