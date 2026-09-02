<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores expense amounts as an exact 2-scale decimal string, not a float', function () {
    $expense = Expense::factory()->create([
        'amount' => 19.99,
        'currency' => 'ILS',
    ]);

    $fresh = $expense->fresh();

    // decimal:2 cast returns a string, preserving exact scale.
    expect($fresh->amount)->toBeString()
        ->and($fresh->amount)->toBe('19.99')
        ->and($fresh->currency)->toBe('ILS');
});

it('keeps decimal precision without floating point drift', function () {
    $expense = Expense::factory()->create(['amount' => 0.1 + 0.2]); // 0.30000000000000004 as float

    expect($expense->fresh()->amount)->toBe('0.30');
});

it('stores AI usage cost with 6-decimal precision', function () {
    $usage = UsageEvent::factory()->create(['cost' => 0.001234]);

    expect($usage->fresh()->cost)->toBeString()
        ->and($usage->fresh()->cost)->toBe('0.001234');
});
