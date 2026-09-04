<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$dashboardRoutes = [
    'dashboard',
    'dashboard.conversations',
    'dashboard.messages',
    'dashboard.tasks',
    'dashboard.reminders',
    'dashboard.expenses',
    'dashboard.whatsapp',
];

it('redirects guests from every dashboard page to login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with($dashboardRoutes);

it('forbids authenticated non-admin users from every dashboard page', function (string $route) {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with($dashboardRoutes);

it('allows an admin to open every dashboard page', function (string $route) {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route($route))->assertOk();
})->with($dashboardRoutes);

it('exposes the admin capability through the model helper', function () {
    expect(User::factory()->create(['is_admin' => true])->isAdmin())->toBeTrue()
        ->and(User::factory()->create(['is_admin' => false])->isAdmin())->toBeFalse();
});
