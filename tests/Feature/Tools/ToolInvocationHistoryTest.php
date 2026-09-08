<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationStatus;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\Memory;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase F2 — invocation history is DURABLE and APPEND-ONLY, using the patterns
 * this repository already established for its immutable records:
 *
 *  - `subscriber_id` is a historical reference WITHOUT a foreign key, exactly
 *    as `customer_payments`, `subscription_events` and `usage_events` do, so
 *    deleting the account cascades the live rows away and never erases the
 *    execution history the ledger points at;
 *  - `tool_invocation_events.tool_invocation_id` is `restrictOnDelete`, exactly
 *    as `customer_payment_events` and `cost_invoice_events` are, so an
 *    invocation cannot be deleted while its history exists;
 *  - the event model uses `ImmutableFinancialRecord` — the same trait 21 other
 *    records use — so any update or delete through the model throws.
 */
beforeEach(function () {
    $this->subscriber = User::factory()->create();
    Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => 'a note about coffee']);

    auth()->setUser($this->subscriber);
    app(ToolConsentService::class)->grant($this->subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    $this->invocation = f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'coffee'])->invocation;
});

it('keeps the invocation, its events, its audit and its ledger row when the account is deleted', function () {
    $row = $this->invocation;
    $subscriberId = $this->subscriber->id;

    expect($row->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and(f2Usage($row))->toHaveCount(1);

    // No FK on subscriber_id, so this cannot cascade the history away…
    $this->subscriber->delete();

    expect(User::query()->whereKey($subscriberId)->exists())->toBeFalse()
        ->and(ToolInvocation::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and($row->fresh()->subscriber_id)->toBe($subscriberId)  // the attribution snapshot survives
        ->and($row->fresh()->status)->toBe(ToolInvocationStatus::Succeeded)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
        ->and(f2Audits($row->fresh()))->toHaveCount(1)
        // …and the object `usage_events.tool_invocation_ref` points at still resolves.
        ->and(f2Usage($row->fresh()))->toHaveCount(1)
        ->and(ToolInvocation::query()->whereKey((int) f2Usage($row->fresh())->first()->tool_invocation_ref)->exists())->toBeTrue();

    // The message went with the account; the invocation kept its own identity.
    expect($row->fresh()->message_id)->toBeNull()
        ->and($row->fresh()->idempotency_key)->toBe($row->idempotency_key);
});

it('refuses at the DATABASE to delete an invocation that still has history', function () {
    $row = $this->invocation;

    expect(fn () => DB::transaction(fn () => DB::table('tool_invocations')->where('id', $row->id)->delete()))
        ->toThrow(QueryException::class);

    expect(ToolInvocation::query()->whereKey($row->id)->exists())->toBeTrue()
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4);

    // There is no application path that hard-deletes invocation history either.
    $paths = [];

    foreach (array_merge(glob(app_path('*/*.php')), glob(app_path('*/*/*.php')), glob(app_path('*/*/*/*.php'))) as $file) {
        $source = php_strip_whitespace($file);

        if (preg_match('/ToolInvocation(Event)?::query\(\)->[a-zA-Z>()\-\', ]*delete|DB::table\([\'"]tool_invocations?[\'"]\)[^;]*delete|DB::table\([\'"]tool_invocation_events[\'"]\)[^;]*delete/', $source) === 1) {
            $paths[] = str_replace(app_path().'/', '', $file);
        }
    }

    expect($paths)->toBe([]);
});

it('makes an event append-only through the same trait the rest of the immutable records use', function () {
    $event = ToolInvocationEvent::query()->where('tool_invocation_id', $this->invocation->id)->firstOrFail();
    $original = $event->to_status;

    // Application layer: the model refuses both, exactly as CustomerPaymentEvent does.
    expect(fn () => $event->update(['to_status' => 'failed']))->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableFinancialRecordException::class)
        // There is no updated_at to move in the first place.
        ->and(Schema::hasColumn('tool_invocation_events', 'updated_at'))->toBeFalse()
        ->and(ToolInvocationEvent::UPDATED_AT)->toBeNull()
        ->and(in_array(ImmutableFinancialRecord::class, class_uses(ToolInvocationEvent::class), true))->toBeTrue();

    // Nothing reached the table: the stored row is still the claim event.
    $unchanged = $event->fresh();

    expect($unchanged->to_status)->toBe($original)
        ->and($original)->toBe(ToolInvocationStatus::Planned)
        ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $this->invocation->id)->count())->toBe(4);
});

it('stores no subscriber content anywhere, with real personal data as the actual input', function () {
    $subscriber = User::factory()->create(['name' => 'عمر شاهين', 'email' => 'omar.shahin@example.com', 'phone' => '+970599123456']);

    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    // Every one of these is a LEGAL `memory.read@1.query`: bounded, no control
    // characters, under 200 characters — and every one is personal.
    $inputs = [
        'عمر شاهين',
        'omar.shahin@example.com',
        '+970599123456',
        'زوجتي تكره القهوة وعندها موعد عند الطبيب النفسي يوم الخميس',
        'my bank account password reset question',
    ];

    Memory::factory()->create(['user_id' => $subscriber->id, 'content' => 'زوجتي تكره القهوة']);

    $rows = [];

    foreach ($inputs as $query) {
        $result = f2Executor()->call(f2Message($subscriber), 'memory.read@1', ['query' => $query]);

        expect($result->invocation)->not->toBeNull($query);
        $rows[] = $result->invocation->fresh();
    }

    foreach ($rows as $row) {
        // The row keeps the hash and the field NAMES — never the words.
        expect($row->input)->toBe([])
            ->and($row->input_fields)->toBe(['query'])
            ->and($row->input_hash)->toHaveLength(64);
    }

    $serialised = json_encode([
        ToolInvocation::query()->get()->toArray(),
        ToolInvocationEvent::query()->get()->toArray(),
        DB::table('audit_logs')->get(),
        DB::table('usage_events')->get(),
    ], JSON_UNESCAPED_UNICODE);

    foreach (array_merge($inputs, [$subscriber->name, $subscriber->email, $subscriber->phone, 'عمر', 'زوجتي', 'password']) as $secret) {
        expect(str_contains($serialised, (string) $secret))->toBeFalse('the invocation record must not carry: '.$secret);
    }

    // The hash is still there, and it is still what decides replay versus conflict.
    expect($serialised)->toContain($rows[0]->input_hash)
        ->and($rows[0]->input_hash)->not->toBe($rows[1]->input_hash);
});
