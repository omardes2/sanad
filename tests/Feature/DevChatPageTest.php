<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Jobs\ProcessInboundMessage;
use App\Livewire\Dev\Chat;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the dev chat page in the testing environment', function () {
    User::factory()->create(['name' => 'مستخدم تجريبي']);

    $this->get('/dev/chat')
        ->assertOk()
        ->assertSee('محاكي المحادثة', escape: false)
        ->assertSee('dir="rtl"', escape: false);
});

it('returns 404 for the dev chat page in production', function () {
    app()['env'] = 'production';

    $this->get('/dev/chat')->assertNotFound();
});

it('sends a message: stores an inbound row and queues processing', function () {
    Queue::fake();
    $user = User::factory()->create();

    Livewire::test(Chat::class)
        ->assertSet('selectedUserId', $user->id)
        ->set('body', 'مرحبا')
        ->call('send')
        ->assertSet('body', '');

    $inbound = Message::where('direction', MessageDirection::Inbound)->first();
    expect($inbound)->not->toBeNull()
        ->and($inbound->text_content)->toBe('مرحبا');

    // A Web channel account and conversation were provisioned for the user.
    expect(ChannelAccount::where('channel', ChannelType::Web)->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(Conversation::where('user_id', $user->id)->exists())->toBeTrue();

    Queue::assertPushed(ProcessInboundMessage::class, 1);
});

it('validates that the message body is required', function () {
    User::factory()->create();

    Livewire::test(Chat::class)
        ->set('body', '')
        ->call('send')
        ->assertHasErrors(['body' => 'required']);
});

it('validates the maximum message length', function () {
    User::factory()->create();

    Livewire::test(Chat::class)
        ->set('body', str_repeat('ا', 2001))
        ->call('send')
        ->assertHasErrors(['body' => 'max']);
});

it('generates a collision-safe UUID as the external message id (not a timestamp)', function () {
    Queue::fake();
    User::factory()->create();

    Livewire::test(Chat::class)
        ->set('body', 'مرحبا')
        ->call('send');

    $externalId = Message::where('direction', MessageDirection::Inbound)->first()->external_message_id;

    // Shaped like "web-<uuid-v4>", not a numeric/timestamp value.
    expect($externalId)->toStartWith('web-')
        ->and(substr($externalId, 4))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i')
        ->and($externalId)->not->toMatch('/^web-\d+$/'); // not "web-<timestamp>"
});

it('shows the queued reply after the job runs (end to end)', function () {
    $user = User::factory()->create();

    Livewire::test(Chat::class)
        ->set('body', 'مرحبا')
        ->call('send');

    $inbound = Message::where('direction', MessageDirection::Inbound)->first();
    pipelineRunJob($inbound->id);

    Livewire::test(Chat::class)
        ->assertSee('أهلًا! أنا سَنَد', escape: false);
});
