<?php

namespace App\Support;

use App\Models\User;

/**
 * Acceso a la reactivación de artículos dados de BAJA.
 *
 * BAJA es, por diseño (ver Item::canTransition), un estado que no admite
 * salida manual libre -- análogo a como DEVUELTO solo sale mediante la
 * revisión formal (RevisionDevolucionService). Un artículo dado de baja
 * representa una decisión deliberada de desecho, así que revertirla no
 * debe ser tan casual como cualquier otro cambio de estado.
 *
 * Aun así, los errores de captura pasan (se marcó BAJA por equivocación,
 * o después resulta que sí es reparable), así que se habilita una salida
 * controlada: BAJA -> DISPONIBLE | REPARACION, mediante `items.cambiar_estado`
 * de siempre PERO reservado a usuarios con el rol Admin, de forma análoga
 * a `cxc.reversar_abono` / `creditos.configurar` / `configuracion.editar`.
 *
 * Matriz segura:
 *   Admin        -> items.reactivar_baja
 *   cualquier otro rol -> nunca (ver assertRolesSeguros)
 */
final class ItemsAcceso
{
    public const PERMISO_REACTIVAR_BAJA = 'items.reactivar_baja';

    public const ROL_ADMIN = 'Admin';

    public static function puedeReactivarBaja(User $user): bool
    {
        return $user->hasRole(self::ROL_ADMIN) && $user->hasPermissionTo(self::PERMISO_REACTIVAR_BAJA);
    }

    /**
     * Prohíbe asignar items.reactivar_baja a un rol distinto de Admin.
     */
    public static function assertRolConReactivacionSegura(string $rol, array $permisos): void
    {
        if (in_array(self::PERMISO_REACTIVAR_BAJA, $permisos, true) && $rol !== self::ROL_ADMIN) {
            throw new \InvalidArgumentException(
                sprintf('items.reactivar_baja solo puede asignarse al rol Admin, no a %s.', $rol)
            );
        }
    }

    /**
     * Valida un mapa rol => permisos antes de sincronizar.
     */
    public static function assertRolesSeguros(array $rolesConPermisos): void
    {
        foreach ($rolesConPermisos as $rol => $permisos) {
            self::assertRolConReactivacionSegura((string) $rol, $permisos);
        }
    }
}
