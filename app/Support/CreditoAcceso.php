<?php

namespace App\Support;

use App\Models\User;

/**
 * Acceso a la configuración de crédito por cliente — estrictamente Admin-only.
 *
 * B15.1
 *
 * La configuración crediticia (credito_habilitado, limite_credito,
 * dias_credito) exige SIEMPRE rol Admin + permiso `creditos.configurar`.
 *
 * Este guard es DOMINIO-limitado (no de la Configuración General): garantiza
 * que `creditos.configurar` nunca se asigne a un rol distinto de Admin, de
 * forma análoga a cómo `ConfiguracionAcceso` protege `configuracion.editar`.
 * Así, la protección server-side no deforma `ConfiguracionAcceso` ni mezcla
 * dominios.
 */
final class CreditoAcceso
{
    public const PERMISO_CONFIGURAR = 'creditos.configurar';

    public const ROL_ADMIN = 'Admin';

    public static function puedeConfigurar(User $user): bool
    {
        return $user->hasRole(self::ROL_ADMIN) && $user->hasPermissionTo(self::PERMISO_CONFIGURAR);
    }

    /**
     * Prohíbe asignar creditos.configurar a un rol distinto de Admin.
     */
    public static function assertRolConPermisoConfigurarSeguro(string $rol, array $permisos): void
    {
        if (in_array(self::PERMISO_CONFIGURAR, $permisos, true) && $rol !== self::ROL_ADMIN) {
            throw new \InvalidArgumentException(
                sprintf('creditos.configurar solo puede asignarse al rol Admin, no a %s.', $rol)
            );
        }
    }

    /**
     * Valida un mapa rol => permisos antes de sincronizar.
     */
    public static function assertRolesSeguros(array $rolesConPermisos): void
    {
        foreach ($rolesConPermisos as $rol => $permisos) {
            self::assertRolConPermisoConfigurarSeguro((string) $rol, $permisos);
        }
    }
}
