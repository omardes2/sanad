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

/*
| Reminder delivery. Both run every minute and are guarded against overlapping
| on one host; correctness does not depend on that guard — the claim is an
| atomic conditional update, so concurrent schedulers on several hosts still
| deliver each reminder once.
|
| `dispatch` claims due reminders and queues them (no attempt is counted and
| nothing is sent by the claim itself). `sweep` recovers reminders stuck in
| `processing` past their lease: back to pending when the retry budget is
| unspent and the reminder is still timely, terminal otherwise.
*/
Schedule::command('sanad:reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('reminders.enabled', true));

Schedule::command('sanad:reminders:sweep')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('reminders.enabled', true));
