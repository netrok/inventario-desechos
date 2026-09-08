<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B14.2
     *
     * Desglose real de cómo se devuelve económicamente un documento postventa.
     *
     * - documento_postventa_id: cancelación/devolución que origina el reembolso.
     * - pago_venta_id: pago original contra el que se revierte dinero.
     *   Puede ser NULL únicamente para ventas históricas sin desglose recuperable.
     * - sesion_caja_id: solo aplica cuando EFECTIVO sale físicamente de una caja.
     * - metodo: EFECTIVO / TARJETA / TRANSFERENCIA / OTRO.
     * - monto: importe efectivamente reembolsado por ese método.
     * - referencia: obligatoria para TARJETA y TRANSFERENCIA.
     * - origen:
     *      AUTOMATICO     -> derivado de PagoVenta.
     *      LEGACY_MANUAL  -> venta histórica sin desglose confiable.
     */
    public function up(): void
    {
        Schema::create('reembolsos_postventa', function (Blueprint $table) {
            $table->id();

            $table->foreignId('documento_postventa_id')
                ->constrained('documentos_postventa')
                ->restrictOnDelete();

            $table->foreignId('pago_venta_id')
                ->nullable()
                ->constrained('pagos_venta')
                ->restrictOnDelete();

            $table->foreignId('sesion_caja_id')
                ->nullable()
                ->constrained('sesiones_caja')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('metodo', 20);

            $table->decimal('monto', 12, 2);

            // Nunca guardar PAN, CVV ni datos sensibles de tarjeta.
            // Solo autorización/folio/referencia segura.
            $table->string('referencia', 100)->nullable();

            $table->string('origen', 20)->default('AUTOMATICO');

            $table->unsignedSmallInteger('orden')->default(0);

            $table->timestamps();

            $table->index('documento_postventa_id');
            $table->index('pago_venta_id');
            $table->index('sesion_caja_id');
            $table->index(['documento_postventa_id', 'orden']);

            // Un documento moderno solo debe generar una reversa por cada
            // PagoVenta fuente. PostgreSQL permite múltiples NULL aquí para legacy.
            $table->unique(
                ['documento_postventa_id', 'pago_venta_id'],
                'reembolsos_postventa_documento_pago_unique'
            );
        });

        DB::statement('
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_monto_check
            CHECK (monto > 0)
        ');

        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_metodo_check
            CHECK (metodo IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'OTRO'))
        ");

        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_check
            CHECK (origen IN ('AUTOMATICO', 'LEGACY_MANUAL'))
        ");

        // Las reversas automáticas deben estar vinculadas a un pago original.
        // Las legacy manuales, por definición, no inventan PagoVenta.
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_pago_check
            CHECK (
                (origen = 'AUTOMATICO' AND pago_venta_id IS NOT NULL)
                OR
                (origen = 'LEGACY_MANUAL' AND pago_venta_id IS NULL)
            )
        ");

        // Tarjeta/transferencia necesitan evidencia operativa segura.
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_referencia_check
            CHECK (
                metodo NOT IN ('TARJETA', 'TRANSFERENCIA')
                OR (
                    referencia IS NOT NULL
                    AND LENGTH(TRIM(referencia)) > 0
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reembolsos_postventa');
    }
};
