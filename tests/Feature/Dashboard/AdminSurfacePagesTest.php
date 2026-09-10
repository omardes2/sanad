<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Enums\ReminderStatus;
use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolConsentStatus;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Livewire\Dashboard\Memory\SubscriberMemory;
use App\Livewire\Dashboard\ReminderDetail;
use App\Livewire\Dashboard\Reminders;
use App\Livewire\Dashboard\Tools\ConsentDetail;
use App\Livewire\Dashboard\Tools\Invocations;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\ToolConsent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolInvocationQuery;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tool invocations: filters, bounds, detail
|--------------------------------------------------------------------------
*/

it('filters invocations by status, tool, capability and refusal reason', function () {
    $subscriber = User::factory()->create();

    admInvocation($subscriber, ['status' => ToolInvocationStatus::Succeeded->value, 'output' => [], 'started_at' => now(), 'finished_at' => now(), 'version' => 4]);
    admInvocation($subscriber, [
        'status' => ToolInvocationStatus::Refused->value,
        'refusal_reason' => ToolInvocationRefusalReason::ExplicitIntentMissing->value,
        'tool_key' => 'memory.write',
        'tool_version' => 1,
        'capability' => ToolCapability::MemoryWrite->value,
        'version' => 2,
    ]);

    $page = Livewire::actingAs(userWithRole(Role::Operations))->test(Invocations::class);

    $page->assertOk()->assertViewHas('totals', fn (array $t) => $t['total'] === 2);

    $page->set('status', ToolInvocationStatus::Refused->value)
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1
            && ($t['by_refusal_reason']['explicit_intent_missing'] ?? 0) === 1);

    $page->set('status', '')->set('tool_key', 'memory.write@1')
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);

    $page->set('tool_key', '')->set('capability', ToolCapability::MemoryWrite->value)
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);
});

it('matches an invocation by its exact idempotency key and input hash', function () {
    $subscriber = User::factory()->create();
    $wanted = admInvocation($subscriber, ['idempotency_key' => 'adm:wanted:key', 'input_hash' => str_repeat('a', 64)]);
    admInvocation($subscriber);

    $page = Livewire::actingAs(userWithRole(Role::Operations))->test(Invocations::class);

    $page->set('idempotency_key', 'adm:wanted:key')
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);

    $page->set('idempotency_key', '')->set('input_hash', str_repeat('a', 64))
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);

    expect($wanted->idempotency_key)->toBe('adm:wanted:key');
});

it('refuses an invocation window longer than the declared maximum', function () {
    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(Invocations::class)
        ->set('from', '2020-01-01')
        ->set('to', '2026-01-01')
        ->assertViewHas('error', fn (?string $e) => $e !== null && str_contains($e, (string) ToolInvocationQuery::MAX_DAYS));
});

it('excludes an invocation outside the window', function () {
    $subscriber = User::factory()->create();
    // `created_at` is not fillable on ToolInvocation (only the store writes the
    // row), so the timestamp is moved after the fact.
    admInvocation($subscriber)->forceFill(['created_at' => now()->subDays(60)])->save();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(Invocations::class)
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 0);
});

/*
|--------------------------------------------------------------------------
| Consent revoke: concurrency, authority, and no grant
|--------------------------------------------------------------------------
*/

it('revokes a consent through the page and records the version bump', function () {
    $subscriber = User::factory()->create();
    $consent = admConsent($subscriber, ToolCapability::MemoryRead);

    // Operations holds subscribers.manage? It does not — Support does.
    $operator = userWithRole(Role::Support);

    Livewire::actingAs($operator)
        ->test(ConsentDetail::class, ['consent' => $consent])
        ->call('revoke')
        ->assertHasNoErrors();

    $fresh = $consent->fresh();

    expect($fresh->status)->toBe(ToolConsentStatus::Revoked)
        ->and($fresh->version)->toBe($consent->version + 1);
});

it('writes nothing when the consent moved since the page was rendered', function () {
    $subscriber = User::factory()->create();
    $consent = admConsent($subscriber, ToolCapability::MemoryRead);

    $component = Livewire::actingAs(userWithRole(Role::Support))
        ->test(ConsentDetail::class, ['consent' => $consent]);

    // Someone else revokes and the subscriber grants again: version moves on.
    auth()->setUser($subscriber);
    app(ToolConsentService::class)->revoke($subscriber->id, ToolCapability::MemoryRead, $consent->version, ToolConsentReason::SubscriberRequest);
    $regranted = app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, $consent->version + 1, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    $before = $regranted->fresh();

    $component->call('revoke')->assertHasErrors('revoke');

    $after = ToolConsent::query()->find($consent->id);

    // Nothing written: the stale decision was refused, not applied.
    expect($after->status)->toBe($before->status)
        ->and($after->version)->toBe($before->version);
});

it('refuses a revoke from an account without the operator permission', function () {
    $subscriber = User::factory()->create();
    $consent = admConsent($subscriber, ToolCapability::MemoryRead);

    // Operations can VIEW consents but does not hold subscribers.manage.
    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ConsentDetail::class, ['consent' => $consent])
        ->call('revoke')
        ->assertHasErrors('revoke');

    expect($consent->fresh()->status)->toBe(ToolConsentStatus::Granted);
});

it('exposes no grant method on the consent component at all', function () {
    expect(method_exists(ConsentDetail::class, 'grant'))->toBeFalse();
});

it('refuses to revoke a consent that is already revoked', function () {
    $subscriber = User::factory()->create();
    $consent = admConsent($subscriber, ToolCapability::MemoryRead, ToolConsentStatus::Revoked);

    Livewire::actingAs(userWithRole(Role::Support))
        ->test(ConsentDetail::class, ['consent' => $consent])
        ->call('revoke')
        ->assertHasErrors('revoke');
});

/*
|--------------------------------------------------------------------------
| Reminders: filters and detail
|--------------------------------------------------------------------------
*/

it('filters reminders by status and by failure reason', function () {
    $subscriber = User::factory()->create();

    Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Sent, 'remind_at' => now()->subDay()]);
    Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Failed,
        'last_error' => 'template_required',
        'remind_at' => now()->subDay(),
    ]);
    Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Failed,
        'last_error' => 'too_late',
        'remind_at' => now()->subDay(),
    ]);

    $page = Livewire::actingAs(userWithRole(Role::Operations))->test(Reminders::class);

    $page->assertOk()->assertViewHas('totals', fn (array $t) => $t['total'] === 3);

    $page->set('status', ReminderStatus::Failed->value)
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 2);

    $page->set('reason', 'template_required')
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);
});

it('ignores a failure-reason filter that is not in the closed set', function () {
    $subscriber = User::factory()->create();
    Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Failed, 'last_error' => 'too_late', 'remind_at' => now()->subDay()]);

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(Reminders::class)
        ->set('reason', "'; drop table reminders; --")
        // Unknown reason is dropped, not turned into SQL.
        ->assertViewHas('totals', fn (array $t) => $t['total'] === 1);

    expect(Reminder::query()->count())->toBe(1);
});

it('reads last_error as a typed reason without an enum cast that could throw', function () {
    $subscriber = User::factory()->create();

    $typed = Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Failed, 'last_error' => 'too_late']);
    $legacy = Reminder::factory()->for($subscriber)->create(['status' => ReminderStatus::Failed, 'last_error' => 'some free text from before']);

    expect($typed->failureReason()?->value)->toBe('too_late')
        ->and($legacy->failureReason())->toBeNull()
        // The raw value survives so the page can render it verbatim.
        ->and($legacy->last_error)->toBe('some free text from before');
});

it('shows a legacy last_error verbatim on the detail page', function () {
    $subscriber = User::factory()->create();
    $reminder = Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Failed,
        'last_error' => 'some free text from before',
    ]);

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ReminderDetail::class, ['reminder' => $reminder])
        ->assertOk()
        ->assertSee('some free text from before');
});

it('flags a claim that outlived its lease without settling', function () {
    config()->set('reminders.lease_seconds', 300);
    $subscriber = User::factory()->create();

    $stale = Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Processing,
        'claimed_at' => now()->subMinutes(30),
    ]);
    $fresh = Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Processing,
        'claimed_at' => now(),
    ]);

    expect($stale->isStaleClaim())->toBeTrue()
        ->and($fresh->isStaleClaim())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Memory: metadata only
|--------------------------------------------------------------------------
*/

it('summarises one subscriber memory state without touching content', function () {
    $subscriber = User::factory()->create();

    Memory::factory()->for($subscriber, 'user')->count(3)->create(['category' => MemoryCategory::Preference]);
    Memory::factory()->for($subscriber, 'user')->archived()->create();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(SubscriberMemory::class, ['subscriber' => $subscriber])
        ->assertOk()
        ->assertViewHas('summary', fn (array $s) => $s['active'] === 3 && $s['archived'] === 1)
        ->assertViewHas('readConsent')
        ->assertViewHas('writeConsent');
});

it('filters memory rows by state, category and provenance', function () {
    $subscriber = User::factory()->create();

    Memory::factory()->for($subscriber, 'user')->create(['category' => MemoryCategory::Fact]);
    Memory::factory()->for($subscriber, 'user')->create(['category' => MemoryCategory::Habit]);
    // Explicit category: the factory otherwise picks one at random, which can
    // collide with the category being filtered for and make this test flaky.
    Memory::factory()->for($subscriber, 'user')->archived()->create(['category' => MemoryCategory::Goal]);

    $page = Livewire::actingAs(userWithRole(Role::Operations))
        ->test(SubscriberMemory::class, ['subscriber' => $subscriber]);

    $page->set('state', 'archived')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    $page->set('state', 'active')->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    $page->set('state', '')->set('category', MemoryCategory::Fact->value)
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    $page->set('category', '')->set('provenance', MemoryProvenance::Inferred->value)
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
});

it('never reports another subscriber memory on a subscriber page', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    Memory::factory()->for($mine, 'user')->count(2)->create();
    Memory::factory()->for($theirs, 'user')->count(5)->create();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(SubscriberMemory::class, ['subscriber' => $mine])
        ->assertViewHas('summary', fn (array $s) => $s['active'] === 2);
});
