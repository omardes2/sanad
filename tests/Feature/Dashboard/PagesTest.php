<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Conversations;
use App\Livewire\Dashboard\Expenses;
use App\Livewire\Dashboard\Messages;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Dashboard\Reminders;
use App\Livewire\Dashboard\Tasks;
use App\Models\Conversation;
use App\Models\Expense;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows live counts on the overview', function () {
    $user = User::factory()->create();
    Conversation::factory()->for($user)->count(2)->create();
    Task::factory()->for($user)->count(3)->create();

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertOk()
        ->assertSee('نظرة عامة')
        ->assertViewHas('stats', fn (array $stats) => $stats['conversations'] === 2);
});

it('lists conversations', function () {
    $user = User::factory()->create(['name' => 'مستخدم تجريبي']);
    Conversation::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(Conversations::class)
        ->assertOk()
        ->assertSee('مستخدم تجريبي');
});

it('lists messages with a truncated body', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    Message::factory()->for($user)->for($conversation)->create(['text_content' => 'رسالة اختبار']);

    Livewire::actingAs($user)
        ->test(Messages::class)
        ->assertOk()
        ->assertSee('رسالة اختبار');
});

it('lists tasks', function () {
    $user = User::factory()->create();
    Task::factory()->for($user)->create(['title' => 'مهمة مهمة']);

    Livewire::actingAs($user)
        ->test(Tasks::class)
        ->assertOk()
        ->assertSee('مهمة مهمة');
});

it('lists reminders', function () {
    $user = User::factory()->create();
    Reminder::factory()->for($user)->create(['title' => 'تذكير مهم']);

    Livewire::actingAs($user)
        ->test(Reminders::class)
        ->assertOk()
        ->assertSee('تذكير مهم');
});

it('lists expenses', function () {
    $user = User::factory()->create();
    Expense::factory()->for($user)->create(['merchant' => 'متجر الاختبار']);

    Livewire::actingAs($user)
        ->test(Expenses::class)
        ->assertOk()
        ->assertSee('متجر الاختبار');
});
