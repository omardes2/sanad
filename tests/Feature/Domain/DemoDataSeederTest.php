<?php

declare(strict_types=1);

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a self-consistent demo dataset', function () {
    $this->seed(DemoDataSeeder::class);

    expect(User::count())->toBe(1)
        ->and(ChannelAccount::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(2)
        ->and(Task::count())->toBe(1)
        ->and(Reminder::count())->toBe(1)
        ->and(Memory::count())->toBe(1)
        ->and(Expense::count())->toBe(3);

    $user = User::first();

    expect($user->phone)->toBe('+970599000001')
        ->and($user->channelAccounts)->toHaveCount(1)
        ->and($user->conversations)->toHaveCount(1)
        ->and($user->tasks->first()->title)->toBe('شراء الحليب');
});

it('runs demo data via DatabaseSeeder in the testing environment', function () {
    expect(app()->environment())->toBe('testing');

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(1);
});

it('does NOT seed demo data when running in production', function () {
    // Laravel rebuilds the application (and re-reads APP_ENV=testing) before
    // each test, so this override is scoped to this test only. We invoke the
    // seeder's run() directly to exercise the environment guard without the
    // console's production-confirmation prompt.
    app()['env'] = 'production';
    expect(app()->environment())->toBe('production');

    $seeder = new DatabaseSeeder;
    $seeder->setContainer(app());
    $seeder->run();

    expect(User::count())->toBe(0);
});
