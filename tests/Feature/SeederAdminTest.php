<?php

use App\Models\User;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    config()->set('seeding.admin_email', null);
    config()->set('seeding.admin_password', null);
    config()->set('seeding.admin_name', 'Admin');
});

test('sin variables admin el seeder crea roles y permisos sin crear usuario', function () {
    $this->seed(RolesAndAdminSeeder::class);

    expect(Role::count())->toBe(6)
        ->and(Role::pluck('name'))->toContain('Admin', 'Almacen', 'Ventas', 'Auditor', 'Operador', 'Consulta')
        ->and(User::count())->toBe(0);
});

test('ambas variables presentes el seeder crea el admin inicial con rol Admin', function () {
    config()->set('seeding.admin_email', 'admin@test.test');
    config()->set('seeding.admin_password', 'PasswordSegura2026!');

    $this->seed(RolesAndAdminSeeder::class);

    $admin = User::where('email', 'admin@test.test')->first();
    expect($admin)->not->toBeNull()
        ->and(Hash::check('PasswordSegura2026!', $admin->password))->toBeTrue()
        ->and($admin->hasRole('Admin'))->toBeTrue();
});

test('solo una variable admin presente lanza un error de configuracion', function (array $overrides) {
    config()->set('seeding.admin_email', $overrides['email'] ?? null);
    config()->set('seeding.admin_password', $overrides['password'] ?? null);

    $this->seed(RolesAndAdminSeeder::class);
})->with([
    'solo email' => [['email' => 'admin@test.test']],
    'solo password' => [['password' => 'PasswordSegura2026!']],
])->throws(RuntimeException::class, 'SEED_ADMIN_EMAIL y SEED_ADMIN_PASSWORD deben configurarse juntos.');

test('la password del admin debe tener al menos 12 caracteres', function () {
    config()->set('seeding.admin_email', 'admin@test.test');
    config()->set('seeding.admin_password', 'Corta123');

    $this->seed(RolesAndAdminSeeder::class);
})->throws(RuntimeException::class, 'SEED_ADMIN_PASSWORD debe tener al menos 12 caracteres.');

test('re-seed no sobrescribe la password de un admin existente', function () {
    config()->set('seeding.admin_email', 'admin@test.test');
    config()->set('seeding.admin_password', 'PasswordInicial2026!');
    $this->seed(RolesAndAdminSeeder::class);

    $admin = User::where('email', 'admin@test.test')->firstOrFail();
    $admin->update(['password' => Hash::make('PasswordNueva2026!')]);

    $this->seed(RolesAndAdminSeeder::class);

    expect(Hash::check('PasswordNueva2026!', $admin->refresh()->password))->toBeTrue()
        ->and(Hash::check('PasswordInicial2026!', $admin->password))->toBeFalse();
});

test('re-seed sincroniza roles y permisos de forma idempotente', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $this->seed(RolesAndAdminSeeder::class);

    expect(Role::count())->toBe(6)
        ->and(\Spatie\Permission\Models\Permission::count())->toBe(30)
        ->and(Role::where('name', 'Admin')->first()->permissions->count())->toBe(30);
});
