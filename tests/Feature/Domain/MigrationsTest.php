<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('runs all migrations from scratch and creates every domain table', function () {
    $tables = [
        'users',
        'channel_accounts',
        'conversations',
        'messages',
        'tasks',
        'reminders',
        'memories',
        'expenses',
        'webhook_events',
        'usage_events',
        'audit_logs',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }
});

it('adds the SANAD-specific columns to users', function () {
    foreach (['phone', 'timezone', 'locale', 'currency', 'preferred_reply_mode', 'status', 'onboarding_completed_at'] as $column) {
        expect(Schema::hasColumn('users', $column))->toBeTrue("missing users.{$column}");
    }
});

it('has no updated_at column on audit_logs', function () {
    expect(Schema::hasColumn('audit_logs', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});
