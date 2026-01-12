<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Limpia cache de Spatie (OBLIGATORIO cuando seeds/permisos cambian)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        // 1) Roles
        $roles = ['Admin', 'Almacen', 'Ventas', 'Auditor'];

        foreach ($roles as $r) {
            Role::firstOrCreate([
                'name'       => $r,
                'guard_name' => $guard,
            ]);
        }

        // 2) Permisos
        $perms = [
            // Dashboard
            'dashboard.ver',

            // Items
            'items.ver',
            'items.crear',
            'items.editar',
            'items.eliminar',
            'items.papelera',
            'items.restaurar',
            'items.borrar_definitivo',
            'items.cambiar_estado',
            'items.mover',

            // Movimientos
            'movimientos.ver',

            // Categorías
            'categorias.ver',
            'categorias.crear',
            'categorias.editar',
            'categorias.eliminar',

            // Ubicaciones
            'ubicaciones.ver',
            'ubicaciones.crear',
            'ubicaciones.editar',
            'ubicaciones.eliminar',

            // (Opcional) catálogos genéricos
            'catalogos.ver',
            'catalogos.editar',

            // (Opcional) usuarios
            'usuarios.gestionar',

            // (Opcional) ventas
            'ventas.ver',
            'ventas.crear',
            'ventas.cerrar',
        ];

        foreach ($perms as $p) {
            Permission::firstOrCreate([
                'name'       => $p,
                'guard_name' => $guard,
            ]);
        }

        // 3) Obtener roles (ya creados)
        $adminRole   = Role::firstWhere(['name' => 'Admin',   'guard_name' => $guard]);
        $almacenRole = Role::firstWhere(['name' => 'Almacen', 'guard_name' => $guard]);
        $ventasRole  = Role::firstWhere(['name' => 'Ventas',  'guard_name' => $guard]);
        $auditorRole = Role::firstWhere(['name' => 'Auditor', 'guard_name' => $guard]);

        // Admin = TODO
        $adminRole->syncPermissions($perms);

        // Almacén
        $almacenRole->syncPermissions([
            'dashboard.ver',
            'items.ver','items.crear','items.editar','items.eliminar',
            'items.cambiar_estado','items.mover',
            'items.papelera','items.restaurar',
            'movimientos.ver',
            'categorias.ver',
            'ubicaciones.ver',
            'catalogos.ver',
        ]);

        // Ventas
        $ventasRole->syncPermissions([
            'dashboard.ver',
            'items.ver',
            'items.cambiar_estado',
            'movimientos.ver',
            'ventas.ver','ventas.crear','ventas.cerrar',
            'categorias.ver',
            'ubicaciones.ver',
        ]);

        // Auditor
        $auditorRole->syncPermissions([
            'dashboard.ver',
            'items.ver',
            'items.papelera',
            'movimientos.ver',
            'categorias.ver',
            'ubicaciones.ver',
            'catalogos.ver',
            'ventas.ver',
        ]);

        // 4) Usuario Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@desechos.test'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('Admin123*'),
            ]
        );

        $admin->syncRoles([$adminRole]);

        // ✅ Limpia cache al final (útil en dev)
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
