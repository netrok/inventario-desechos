<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill de pagos históricos (B13/B12 y anteriores): solo evidencia
     * conocida. monto_aplicado = venta.total, metodo = forma_pago histórica,
     * origen = LEGACY, sesion_caja_id NULL y efectivo/cambio/referencia NULL
     * (nunca se fabrican datos que no existieron).
     * Se omiten ventas LEGACY cuya forma_pago no es reconstructible (OTRO).
     */
    public function up(): void
    {
        DB::statement("
            INSERT INTO pagos_venta (venta_id, sesion_caja_id, user_id, metodo, monto_aplicado,
                                     efectivo_recibido, cambio_entregado, referencia, origen, orden,
                                     created_at, updated_at)
            SELECT v.id, NULL, v.user_id, v.forma_pago, v.total,
                   NULL, NULL, NULL, 'LEGACY', 0,
                   COALESCE(v.created_at, NOW()), COALESCE(v.created_at, NOW())
            FROM ventas v
            WHERE v.forma_pago IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA')
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM pagos_venta WHERE origen = 'LEGACY'");
    }
};
