<?php

use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Run locally with `php artisan schedule:work`, or in production via one
| cron entry (NOT installed by any deployment step — an explicit operator
| decision):
|
|   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
|
| Phase C3: the scheduled provider health run is gated by the
| `ai.health.scheduled` setting (default false) and only ever runs the
| non-billable auth probe — never an inference. The prune keeps the
| health history bounded.
*/
Schedule::command('sanad:ai:health:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) app(SettingsRepository::class)->get('ai.health.scheduled'));

Schedule::command('sanad:ai:health:prune')
    ->daily()
    ->withoutOverlapping();
