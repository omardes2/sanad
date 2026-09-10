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

/*
| Voice-note transcription recovery. Correctness does not depend on this run:
| a voice note reaches transcription from ingestion, and the retry after an
| unproven provider outcome is dispatched by the job itself. The sweep is the
| backstop for the cases a process cannot schedule for itself — a worker killed
| mid-claim, a job lost before it claimed — and it only RE-QUEUES. Every rule
| about ownership, budget and settlement lives in the claim, under a row lock.
*/
Schedule::command('sanad:voice:sweep')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('voice.enabled', true));

/*
| Recurring reminders: materialisation ONLY.
|
| It creates the occurrence rows active schedules are due within the horizon and
| does nothing else — no delivery, no claim, no settlement. Each occurrence is an
| ordinary reminder, so `dispatch` and `sweep` above pick it up knowing nothing
| about recurrence.
|
| Correctness does not depend on `withoutOverlapping`: two concurrent runs cannot
| duplicate an occurrence (the unique occurrence key decides that) and cannot
| write after a cancellation commits (the schedule's status and version are
| re-read under a row lock). The guard is there to save work, not to be right.
*/
Schedule::command('sanad:reminders:materialise')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(static fn (): bool => (bool) config('reminders.enabled', true)
        && (bool) config('reminders.recurrence.enabled', true));
