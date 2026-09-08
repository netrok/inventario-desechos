<?php

namespace App\Support;

use App\Models\User;

/**
 * Acceso al módulo de Cuentas por Cobrar (CxC / cobranza) — B15.4.
 *
 * Matriz segura:
 *   Admin   -> cxc.ver, cxc.abonar, cxc.reversar_abono
 *   Ventas  -> cxc.ver, cxc.abonar (NO reversar)
 *   Auditor -> cxc.ver (solo lectura)
 *   Almacen -> ninguno
 *
 * `cxc.reversar_abono` está reservado a Admin de forma análoga a cómo
 * `configuracion.editar` y `creditos.configurar` son Admin-only: este guard
 * impede que una futura edición del seeder (o UI de roles) entregue el permiso
 * de reversa a un rol no Admin.
 *
 * La cobranza NO reutiliza `creditos.configurar` (que sigue Admin-only y
 * separado, B15.1).
 */
final class CxCAcceso
{
    public const PERMISO_VER = 'cxc.ver';

    public const PERMISO_ABONAR = 'cxc.abonar';

    public const PERMISO_REVERSAR = 'cxc.reversar_abono';

    public const ROL_ADMIN = 'Admin';

    public static function puedeVer(User $user): bool
    {
        return $user->hasPermissionTo(self::PERMISO_VER);
    }

    public static function puedeAbonar(User $user): bool
    {
        return $user->hasPermissionTo(self::PERMISO_ABONAR);
    }

    public static function puedeReversar(User $user): bool
    {
        return $user->hasRole(self::ROL_ADMIN) && $user->hasPermissionTo(self::PERMISO_REVERSAR);
    }

    /**
     * Prohíbe asignar cxc.reversar_abono a un rol distinto de Admin.
     */
    public static function assertRolConReversaSegura(string $rol, array $permisos): void
    {
        if (in_array(self::PERMISO_REVERSAR, $permisos, true) && $rol !== self::ROL_ADMIN) {
            throw new \InvalidArgumentException(
                sprintf('cxc.reversar_abono solo puede asignarse al rol Admin, no a %s.', $rol)
            );
        }
    }

    /**
     * Valida un mapa rol => permisos antes de sincronizar.
     */
    public static function assertRolesSeguros(array $rolesConPermisos): void
    {
        foreach ($rolesConPermisos as $rol => $permisos) {
            self::assertRolConReversaSegura((string) $rol, $permisos);
        }
    }
}
