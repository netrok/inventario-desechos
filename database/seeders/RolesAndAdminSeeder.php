<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Importante para Spatie cuando cambias configs/guard
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles (mínimos)
        $roles = ['Admin', 'Almacen', 'Ventas', 'Auditor'];
        foreach ($roles as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        // Permisos base (luego los ampliamos)
        $perms = [
            'items.ver', 'items.crear', 'items.editar', 'items.eliminar',
            'movimientos.ver',
            'ventas.ver', 'ventas.crear', 'ventas.cerrar',
            'catalogos.ver', 'catalogos.editar',
            'usuarios.gestionar',
        ];

        foreach ($perms as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        // Asignación sugerida
        Role::findByName('Admin')->givePermissionTo(Permission::all());
        Role::findByName('Almacen')->givePermissionTo(['items.ver','items.crear','items.editar','movimientos.ver','catalogos.ver']);
        Role::findByName('Ventas')->givePermissionTo(['items.ver','ventas.ver','ventas.crear','ventas.cerrar','movimientos.ver']);
        Role::findByName('Auditor')->givePermissionTo(['items.ver','movimientos.ver','ventas.ver','catalogos.ver']);

        // Usuario admin (si no existe)
        $admin = User::firstOrCreate(
            ['email' => 'admin@desechos.test'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Admin123*'),
            ]
        );

        if (! $admin->hasRole('Admin')) {
            $admin->assignRole('Admin');
        }
    }
}

$perms = [
    'items.ver','items.crear','items.editar','items.eliminar',
    'items.cambiar_estado','items.mover',
    'items.papelera','items.restaurar','items.borrar_definitivo',
    'movimientos.ver',
    'dashboard.ver',
    'catalogos.ver','catalogos.editar',
    'usuarios.gestionar',
];

Role::findByName('Admin')->givePermissionTo(Permission::all());

Role::findByName('Almacen')->givePermissionTo([
    'items.ver','items.crear','items.editar','items.eliminar',
    'items.cambiar_estado','items.mover',
    'items.papelera','items.restaurar',
    'movimientos.ver','dashboard.ver','catalogos.ver'
]);

Role::findByName('Ventas')->givePermissionTo([
    'items.ver','items.cambiar_estado','movimientos.ver','dashboard.ver'
]);

Role::findByName('Auditor')->givePermissionTo([
    'items.ver','items.papelera','movimientos.ver','dashboard.ver','catalogos.ver'
]);
