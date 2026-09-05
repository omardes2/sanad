<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('recordModelChanges captures dirty attributes (before save) and redacts the model\'s declared sensitive ones', function () {
    $user = User::factory()->create(['name' => 'قبل']);
    $user->forceFill(['name' => 'بعد', 'password' => 'brand-new-password-value']);

    $log = app(AuditLogger::class)->recordModelChanges('users.updated', $user);
    $user->save();

    expect($log)->not->toBeNull()
        ->and($log->changes()['name'])->toBe(['from' => 'قبل', 'to' => 'بعد'])
        ->and($log->changes()['password']['to'])->toStartWith('[REDACTED:')
        ->and(json_encode($log->metadata))->not->toContain('brand-new-password-value');

    // No dirty attributes → nothing is written.
    expect(app(AuditLogger::class)->recordModelChanges('users.updated', $user->fresh()))->toBeNull();
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
