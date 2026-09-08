<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade la dirección explícita (ENTRADA/SALIDA) a los movimientos físicos
     * de caja y refuerza la consistencia tipo/dirección a nivel de BD.
     *
     * Hasta ahora la dirección se derivaba implícitamente del tipo; esta
     * migración la vuelve explícita y la valida para que una fila incoherente
     * (p. ej. RETIRO con dirección ENTRADA) sea imposible incluso si la
     * aplicación tiene un bug.
     */
    public function up(): void
    {
        Schema::table('movimientos_caja', function ($table) {
            $table->string('direccion', 20)->nullable()->after('tipo');
        });

        // Backfill de la dirección a partir del tipo para filas existentes.
        DB::statement("
            UPDATE movimientos_caja
            SET direccion = CASE
                WHEN tipo IN ('COBRO_EFECTIVO', 'ENTRADA_MANUAL') THEN 'ENTRADA'
                WHEN tipo IN ('CAMBIO_ENTREGADO', 'RETIRO', 'REEMBOLSO_EFECTIVO') THEN 'SALIDA'
                ELSE NULL
            END
        ");

        // 1) Dirección válida. El backfill deja AJUSTE (sin dirección histórica
        //    conocida) con NULL; cualquier fila nueva debe tener dirección.
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_direccion_check
            CHECK (direccion IN ('ENTRADA', 'SALIDA'))
        ");

        // 2) Consistencia tipo/dirección (AJUSTE admite ambas; el resto es fija).
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_direccion_check
            CHECK (
                (tipo = 'COBRO_EFECTIVO'      AND direccion = 'ENTRADA')
             OR (tipo = 'ENTRADA_MANUAL'      AND direccion = 'ENTRADA')
             OR (tipo = 'CAMBIO_ENTREGADO'    AND direccion = 'SALIDA')
             OR (tipo = 'RETIRO'              AND direccion = 'SALIDA')
             OR (tipo = 'REEMBOLSO_EFECTIVO'  AND direccion = 'SALIDA')
             OR (tipo = 'AJUSTE'              AND direccion IN ('ENTRADA', 'SALIDA'))
            )
        ");

        // 3) Todas las filas nuevas deben declarar dirección explícitamente.
        DB::statement('
            ALTER TABLE movimientos_caja
            ALTER COLUMN direccion SET NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('movimientos_caja', function ($table) {
            $table->dropColumn('direccion');
        });
    }
};
