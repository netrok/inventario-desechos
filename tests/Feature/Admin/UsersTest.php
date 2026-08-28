<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Limpia cache Spatie (clave para evitar falsos 403)
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guard = 'web';

    // Roles que usa tu módulo / tests
    Role::findOrCreate('Admin', $guard);
    Role::findOrCreate('Operador', $guard);

    // Permisos reales definidos para el módulo de usuarios
    $userPermissions = [
        'usuarios.ver',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.eliminar',
    ];

    foreach ($userPermissions as $permission) {
        Permission::findOrCreate($permission, $guard);
    }

    Role::findByName('Admin', $guard)->syncPermissions($userPermissions);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
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
            'password_confirmation' => 'Password123!',
            'roles' => ['Operador'],
        ])
        ->assertRedirect('/admin/users');

    $user = User::where('email', 'juan@test.com')->firstOrFail();
    expect($user->hasRole('Operador'))->toBeTrue();
});
