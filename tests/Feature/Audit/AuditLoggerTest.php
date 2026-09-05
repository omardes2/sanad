<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('records actor, subject, redacted changes and request context for an authenticated action', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $subject = User::factory()->create();
    $this->actingAs($admin);

    $log = app(AuditLogger::class)->record('users.updated', $subject, [
        'name' => ['from' => 'a', 'to' => 'b'],
        'password' => ['from' => 'old-secret', 'to' => 'new-secret'],
        'api_key' => ['from' => null, 'to' => 'sk-live-abcdefghijklmnop'],
    ], ['note' => 'manual', 'token' => 'should-not-persist']);

    $row = AuditLog::query()->findOrFail($log->id);

    expect($row->user_id)->toBe($admin->id)
        ->and($row->actor)->toBe('user')
        ->and($row->action)->toBe('users.updated')
        ->and($row->subject_type)->toBe($subject->getMorphClass())
        ->and($row->subject_id)->toBe($subject->id)
        ->and($row->changes()['name'])->toBe(['from' => 'a', 'to' => 'b'])
        ->and($row->changes()['password']['from'])->toStartWith('[REDACTED:')
        ->and($row->changes()['password']['to'])->toStartWith('[REDACTED:')
        ->and($row->changes()['password']['from'])->not->toBe($row->changes()['password']['to']) // change still detectable
        ->and($row->changes()['api_key']['from'])->toBeNull()
        ->and($row->changes()['api_key']['to'])->toStartWith('[REDACTED:')
        ->and($row->context()['note'])->toBe('manual')
        ->and($row->context()['token'])->toStartWith('[REDACTED:')
        ->and(json_encode($row->metadata))->not->toContain('old-secret')
        ->and(json_encode($row->metadata))->not->toContain('new-secret')
        ->and(json_encode($row->metadata))->not->toContain('sk-live')
        ->and(json_encode($row->metadata))->not->toContain('should-not-persist');
});

it('records a console actor with no user when nobody is authenticated', function () {
    $log = app(AuditLogger::class)->record('rbac.test', null, [], ['source' => 'artisan']);

    expect($log->user_id)->toBeNull()
        ->and($log->actor)->toBeIn(['console', 'system'])
        ->and($log->context()['source'])->toBe('artisan')
        ->and($log->updated_at)->toBeNull(); // append-only: no updated_at column
});

it('saveWithAudit saves the model and records its diff, redacting declared sensitive attributes', function () {
    $user = User::factory()->create(['name' => 'قبل']);
    $user->forceFill(['name' => 'بعد', 'password' => 'brand-new-password-value']);

    $log = app(AuditLogger::class)->saveWithAudit('users.updated', $user);

    expect($log)->not->toBeNull()
        ->and($user->fresh()->name)->toBe('بعد') // the change really happened
        ->and($log->changes()['name'])->toBe(['from' => 'قبل', 'to' => 'بعد'])
        ->and($log->changes()['password']['to'])->toStartWith('[REDACTED:')
        ->and(json_encode($log->metadata))->not->toContain('brand-new-password-value');

    // No dirty attributes → nothing saved, nothing written.
    expect(app(AuditLogger::class)->saveWithAudit('users.updated', $user->fresh()))->toBeNull()
        ->and(AuditLog::count())->toBe(1);
});

it('atomicity: a change that fails at the database leaves NO audit row (rolled back together)', function () {
    $taken = User::factory()->create(['email' => 'taken@example.test']);
    $user = User::factory()->create(['name' => 'أصلي']);
    $user->forceFill(['name' => 'معدَّل', 'email' => 'taken@example.test']); // unique violation

    expect(fn () => app(AuditLogger::class)->saveWithAudit('users.updated', $user))->toThrow(QueryException::class);

    expect(AuditLog::count())->toBe(0)
        ->and($user->fresh()->name)->toBe('أصلي')
        ->and(DB::transactionLevel())->toBe(1); // RefreshDatabase's own transaction is intact

    // The connection is still usable afterwards (no aborted transaction).
    expect(User::count())->toBe(2)->and($taken->exists)->toBeTrue();
});

it('atomicity: a change vetoed by a saving listener is neither saved nor audited', function () {
    $user = User::factory()->create(['name' => 'ثابت']);
    User::saving(static fn (): bool => false);

    $user->forceFill(['name' => 'مرفوض']);

    expect(app(AuditLogger::class)->saveWithAudit('users.updated', $user))->toBeNull()
        ->and(AuditLog::count())->toBe(0)
        ->and($user->fresh()->name)->toBe('ثابت');
});

it('atomicity: record() inside a caller transaction is rolled back with the caller\'s change', function () {
    $user = User::factory()->create(['name' => 'قبل']);

    try {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['name' => 'بعد'])->save();
            app(AuditLogger::class)->record('users.updated', $user, ['name' => ['from' => 'قبل', 'to' => 'بعد']]);

            throw new RuntimeException('later step failed');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::count())->toBe(0)
        ->and($user->fresh()->name)->toBe('قبل');
});

it('keeps the historical actor after the admin account is deleted (actor_ref snapshot, no PII)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $log = app(AuditLogger::class)->record('settings.updated', null, ['x' => ['from' => 1, 'to' => 2]]);

    expect($log->user_id)->toBe($admin->id)
        ->and($log->actor)->toBe('user')
        ->and($log->actor_ref)->toBe('user:'.$admin->id);

    auth()->logout();
    $admin->delete();

    $row = AuditLog::query()->findOrFail($log->id);

    expect($row->user_id)->toBeNull() // live FK nulled on delete
        ->and($row->actor_ref)->toBe('user:'.$admin->id) // history keeps its author
        ->and($row->actor)->toBe('user')
        ->and(json_encode($row->toArray()))->not->toContain($admin->email)
        ->and(json_encode($row->toArray()))->not->toContain($admin->name);
});

it('stamps console/system actors in actor_ref as well', function () {
    $log = app(AuditLogger::class)->record('rbac.test');

    expect($log->actor_ref)->toBe($log->actor)
        ->and($log->actor_ref)->toBeIn(['console', 'system']);
});

it('redacts secret-looking VALUES even under an innocent key (defensive layer)', function () {
    $log = app(AuditLogger::class)->record('x', null, [], [
        'header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.something',
        'note' => 'gsk_abcdefghijklmnopqrstuvwxyz',
        'plain' => 'hello',
    ]);

    expect($log->context()['header'])->toStartWith('[REDACTED:')
        ->and($log->context()['note'])->toStartWith('[REDACTED:')
        ->and($log->context()['plain'])->toBe('hello');
});
