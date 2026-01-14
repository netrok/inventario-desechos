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
        // Limpia cache de Spatie (OBLIGATORIO cuando cambias permisos/roles)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        /**
         * Roles
         */
        $roles = [
            'Admin',
            'Almacen',
            'Ventas',
            'Auditor',
            // si quieres conservar estos del segundo seeder, déjalos:
            'Operador',
            'Consulta',
        ];

        foreach ($roles as $r) {
            Role::firstOrCreate([
                'name'       => $r,
                'guard_name' => $guard,
            ]);
        }

        /**
         * Permisos
         */
        $perms = [
            // Dashboard
            'dashboard.ver',

            // Items
            'items.ver',
            'items.crear',
            'items.editar',
            'items.eliminar',

            // Acciones extra (si las usas en rutas/middlewares)
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

            // Catálogos genéricos (opcional)
            'catalogos.ver',
            'catalogos.editar',

            // Usuarios / Admin (opcional pero recomendado)
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.eliminar',
            'usuarios.roles',

            // Ventas (opcional)
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

        /**
         * Asignación de permisos por rol
         */
        $adminRole   = Role::where('name', 'Admin')->where('guard_name', $guard)->firstOrFail();
        $almacenRole = Role::where('name', 'Almacen')->where('guard_name', $guard)->firstOrFail();
        $ventasRole  = Role::where('name', 'Ventas')->where('guard_name', $guard)->firstOrFail();
        $auditorRole = Role::where('name', 'Auditor')->where('guard_name', $guard)->firstOrFail();

        // Admin = todo
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

        // Auditor (solo lectura)
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

        /**
         * Usuario Admin (elige UN correo y UNA contraseña)
         */
        $adminEmail = 'admin@desechos.test'; // o 'admin@gv.com.mx' si ya lo usas en prod/dev
        $adminPass  = 'Admin123*';           // cámbiala luego en cuanto entres

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name'     => 'Admin',
                'password' => Hash::make($adminPass),
            ]
        );

        $admin->syncRoles(['Admin']);

        // Limpia cache al final (útil en dev)
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}