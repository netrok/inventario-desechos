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

it('impide a un Admin borrar un usuario que tiene ventas y conserva el actor', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $vendedor = User::factory()->create();
    $venta = \App\Models\Venta::create([
        'user_id' => $vendedor->id,
        'total' => 250.5,
        'forma_pago' => 'EFECTIVO',
    ]);

    $response = $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $vendedor));

    // Sin 500: feedback limpio a la UI.
    $response->assertSessionHas('error', 'No se puede eliminar este usuario porque tiene ventas registradas.');

    $this->assertDatabaseHas('users', ['id' => $vendedor->id]);
    $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'user_id' => $vendedor->id]);
});

it('conserva la regla de no eliminar al ultimo Admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin'); // único Admin

    // Usuario sin rol Admin pero con permiso de eliminación.
    $manager = User::factory()->create();
    $manager->givePermissionTo('usuarios.eliminar');

    $this->actingAs($manager)
        ->delete(route('admin.users.destroy', $admin))
        ->assertSessionHas('error', 'No puedes eliminar al último Admin.');

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

it('permite a un Admin borrar un usuario sin ventas (y sin ser ultimo admin)', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $otroAdmin = User::factory()->create();
    $otroAdmin->assignRole('Admin');

    $vendedor = User::factory()->create();
    $vendedor->assignRole('Operador');

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $vendedor))
        ->assertRedirect('/admin/users');

    $this->assertDatabaseMissing('users', ['id' => $vendedor->id]);
});
