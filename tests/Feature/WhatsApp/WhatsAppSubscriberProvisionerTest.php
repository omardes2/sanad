<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Models\ChannelAccount;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSubscriberProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a subscriber and account once, then reuses it', function () {
    $provisioner = app(WhatsAppSubscriberProvisioner::class);

    $first = $provisioner->resolve('+970599111111', 'سارة');
    $second = $provisioner->resolve('+970599111111', 'سارة مختلفة');

    expect($second->id)->toBe($first->id)
        ->and(ChannelAccount::count())->toBe(1)
        ->and(User::count())->toBe(1)
        ->and($first->user->is_admin)->toBeFalse();
});

it('falls back to a generic Arabic display name when the profile name is missing', function () {
    $account = app(WhatsAppSubscriberProvisioner::class)->resolve('+970599222222', null);

    expect($account->display_name)->toBeNull()
        ->and($account->user->name)->toBe('مشترك واتساب');
});

it('keeps each number as a distinct subscriber', function () {
    $provisioner = app(WhatsAppSubscriberProvisioner::class);
    $provisioner->resolve('+970599333333', 'أ');
    $provisioner->resolve('+970599444444', 'ب');

    expect(ChannelAccount::query()->where('channel', ChannelType::WhatsApp)->count())->toBe(2)
        ->and(User::query()->where('is_admin', false)->count())->toBe(2);
});
