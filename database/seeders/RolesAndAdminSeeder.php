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
                'name' => $r,
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

            // Acciones de ciclo de vida
            'items.cambiar_estado',
            'items.mover',

            // Reportes
            'reportes.ver',

            // Ventas / Punto de venta
            'ventas.ver',
            'ventas.crear',

            // Postventa: cancelación (reversa total) y devoluciones
            'ventas.cancelar',
            'ventas.devolver',

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

            // Usuarios / Admin
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.eliminar',

            // Clientes
            'clientes.ver',
            'clientes.crear',
            'clientes.editar',
            'clientes.desactivar',

            // Configuración general
            'configuracion.ver',
            'configuracion.editar',
        ];

        foreach ($perms as $p) {
            Permission::firstOrCreate([
                'name' => $p,
                'guard_name' => $guard,
            ]);
        }

        /**
         * Asignación de permisos por rol
         */
        $adminRole = Role::where('name', 'Admin')->where('guard_name', $guard)->firstOrFail();
        $almacenRole = Role::where('name', 'Almacen')->where('guard_name', $guard)->firstOrFail();
        $ventasRole = Role::where('name', 'Ventas')->where('guard_name', $guard)->firstOrFail();
        $auditorRole = Role::where('name', 'Auditor')->where('guard_name', $guard)->firstOrFail();

        // Almacén (operativo de inventario)
        $almacenPermisos = [
            'dashboard.ver',
            'items.ver', 'items.crear', 'items.editar',
            'items.cambiar_estado', 'items.mover',
            'reportes.ver',
            'categorias.ver', 'categorias.crear', 'categorias.editar',
            'ubicaciones.ver', 'ubicaciones.crear', 'ubicaciones.editar',
        ];

        // Ventas (POS: consulta + registro de ventas + devoluciones; NO cancela).
        // La cancelación es una reversa financiera total reservada a Admin.
        // Clientes: puede ver/crear/editar (para POS necesita clientes), pero
        // NO desactivar (acción de control reservada a Admin).
        $ventasPermisos = [
            'dashboard.ver',
            'items.ver',
            'ventas.ver',
            'ventas.crear',
            'ventas.devolver',
            'clientes.ver',
            'clientes.crear',
            'clientes.editar',
        ];

        // Auditor (solo lectura) + consulta de configuración y clientes.
        $auditorPermisos = [
            'dashboard.ver',
            'items.ver',
            'reportes.ver',
            'categorias.ver',
            'ubicaciones.ver',
            'ventas.ver',
            'clientes.ver',
            'configuracion.ver',
        ];

        // Guard server-side: la Configuración General solo puede editarla Admin.
        // configuracion.editar queda prohibido para cualquier rol no Admin, aunque
        // más adelante alguien edite este seeder o exista una futura UI de roles.
        \App\Support\ConfiguracionAcceso::assertRolesSeguros([
            'Admin' => $perms,
            'Almacen' => $almacenPermisos,
            'Ventas' => $ventasPermisos,
            'Auditor' => $auditorPermisos,
        ]);

        // Admin = todo
        $adminRole->syncPermissions($perms);

        $almacenRole->syncPermissions($almacenPermisos);

        $ventasRole->syncPermissions($ventasPermisos);

        $auditorRole->syncPermissions($auditorPermisos);

        /**
         * Usuario Admin inicial.
         *
         * Solo se crea cuando las credenciales se proporcionan explícitamente
         * mediante variables de entorno. Nunca deben existir credenciales
         * predeterminadas dentro del repositorio.
         */
        // vía config('seeding.*') para que funcione aunque config:cache esté activo.
        $adminEmail = config('seeding.admin_email');
        $adminPass = config('seeding.admin_password');
        $adminName = config('seeding.admin_name', 'Admin');

        if (($adminEmail && ! $adminPass) || (! $adminEmail && $adminPass)) {
            throw new \RuntimeException(
                'SEED_ADMIN_EMAIL y SEED_ADMIN_PASSWORD deben configurarse juntos.'
            );
        }

        if ($adminEmail && $adminPass) {
            if (strlen($adminPass) < 12) {
                throw new \RuntimeException(
                    'SEED_ADMIN_PASSWORD debe tener al menos 12 caracteres.'
                );
            }

            $admin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => Hash::make($adminPass),
                ]
            );

            $admin->syncRoles(['Admin']);
        }

        // Limpia cache al final (útil en dev)
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
