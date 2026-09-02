<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| The scheduler is wired and ready. Run it locally with `php artisan
| schedule:work`, or in production via a single cron entry:
|
|   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
|
| No product tasks are scheduled in Sprint 0. Future example (e.g. the daily
| summary) will look like:
|
|   Schedule::command('sanad:daily-summary')
|       ->dailyAt('08:00')
|       ->timezone(config('sanad.default_timezone'));
|
*/
