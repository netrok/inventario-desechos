<?php

namespace App\Support;

use App\Models\User;

/**
 * Acceso a la Configuración General — estrictamente Admin-only.
 *
 * La actualización de configuración exige SIEMPRE rol Admin + permiso
 * configuracion.editar. Un usuario no Admin al que se le asigne el permiso por
 * error o manipulación recibe HTTP 403 (defensa en el middleware de la ruta y
 * en el controlador). Además, `configuracion.editar` nunca debe poder asignarse
 * a un rol diferente de Admin.
 *
 * Nota sobre el alcance del guard: el guard protege el mapa/seeder actual.
 * Cualquier futura administración de roles o permisos deberá reutilizar
 * explícitamente esta validación.
 */
final class ConfiguracionAcceso
{
    public const PERMISO_VER = 'configuracion.ver';

    public const PERMISO_EDITAR = 'configuracion.editar';

    public const ROL_ADMIN = 'Admin';

    public static function puedeVer(User $user): bool
    {
        return $user->hasPermissionTo(self::PERMISO_VER);
    }

    public static function puedeEditar(User $user): bool
    {
        return $user->hasRole(self::ROL_ADMIN) && $user->hasPermissionTo(self::PERMISO_EDITAR);
    }

    /**
     * Prohíbe asignar configuracion.editar a un rol distinto de Admin.
     *
     * Evita escalamiento accidental: si un rol no Admin llega a contener el
     * permiso (por edición del seeder, manipulación o futura UI), esta defensa
     * lo rechaza de forma controlada.
     */
    public static function assertRolConPermisoEditarSeguro(string $rol, array $permisos): void
    {
        if (in_array(self::PERMISO_EDITAR, $permisos, true) && $rol !== self::ROL_ADMIN) {
            throw new \InvalidArgumentException(
                sprintf('configuracion.editar solo puede asignarse al rol Admin, no a %s.', $rol)
            );
        }
    }

    /**
     * Valida un mapa rol => permisos antes de sincronizar.
     */
    public static function assertRolesSeguros(array $rolesConPermisos): void
    {
        foreach ($rolesConPermisos as $rol => $permisos) {
            self::assertRolConPermisoEditarSeguro((string) $rol, $permisos);
        }
    }
}
