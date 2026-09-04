<?php

declare(strict_types=1);

use App\Livewire\Dashboard\WhatsAppStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('reports the integration as enabled when configured', function () {
    whatsappConfigure();
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WhatsAppStatus::class)
        ->assertOk()
        ->assertSee('مفعّل')
        ->assertViewHas('enabled', true)
        ->assertViewHas('canSend', true);
});

it('reports the integration as disabled when turned off', function () {
    whatsappConfigure(['whatsapp.enabled' => false]);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WhatsAppStatus::class)
        ->assertOk()
        ->assertSee('غير مفعّل')
        ->assertViewHas('enabled', false)
        ->assertViewHas('canSend', false);
});

it('never renders any token, app secret, or verify token value', function () {
    whatsappConfigure(); // sets real-looking test credentials in config
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WhatsAppStatus::class)
        ->assertOk()
        ->assertDontSee('TEST_ACCESS_TOKEN')
        ->assertDontSee('test-app-secret')
        ->assertDontSee('test-verify-token')
        ->assertDontSee('PNID_123')
        ->assertDontSee('WABA_123');
});

it('shows the config checklist as present when fully configured', function () {
    whatsappConfigure();
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(WhatsAppStatus::class)
        ->assertViewHas('checklist', function (array $checklist) {
            return collect($checklist)->every(fn ($present) => $present === true);
        });
});
