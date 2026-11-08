<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling is deliberate: Google Sheets has no dependable push notification for
// ordinary consumer sheets, so connected sheets are polled every five minutes.
Schedule::command('sheets:sync')->everyFiveMinutes()->withoutOverlapping();

// Morning summary of tasks, bugs and tracked time through Telegram.
Schedule::command('telegram:daily-digest')
    ->dailyAt('08:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping();
