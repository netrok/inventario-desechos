<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate('dashboard.ver', 'web');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('redirects guests from dashboard to login', function () {
    $this->get('/dashboard')
        ->assertRedirect('/login');
});

it('blocks authenticated users without dashboard permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertForbidden();
});

it('allows users with dashboard permission to view dashboard', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('dashboard.ver');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});
