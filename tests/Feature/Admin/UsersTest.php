<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('Admin');
    Role::findOrCreate('Operador');
});

it('blocks non-admin users from admin users index', function () {
    $u = User::factory()->create();
    $u->assignRole('Operador');

    $this->actingAs($u)
        ->get('/admin/users')
        ->assertStatus(403);
});

it('allows admin to access admin users index', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk();
});

it('allows admin to create a user and assign roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->post('/admin/users', [
            'name' => 'Juan Test',
            'email' => 'juan@test.com',
            'password' => 'Password123!',
            'roles' => ['Operador'],
        ])
        ->assertRedirect('/admin/users');

    $user = User::where('email', 'juan@test.com')->firstOrFail();
    expect($user->hasRole('Operador'))->toBeTrue();
});
