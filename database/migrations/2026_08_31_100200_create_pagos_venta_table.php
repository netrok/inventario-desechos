<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagos de venta: cómo se cubrió económicamente cada venta.
     * Fuente autoritativa B14 (ventas.forma_pago se conserva com derivado).
     * Montos siempre positivos y con comprobación server-side vía Money.
     */
    public function up(): void
    {
        Schema::create('pagos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $table->foreignId('sesion_caja_id')->nullable()->constrained('sesiones_caja')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('metodo', 20); // EFECTIVO, TARJETA, TRANSFERENCIA (CREDITO reservado para B15)
            $table->decimal('monto_aplicado', 12, 2);
            $table->decimal('efectivo_recibido', 12, 2)->nullable();
            $table->decimal('cambio_entregado', 12, 2)->nullable();
            $table->string('referencia', 100)->nullable(); // solo folio/referencia segura, nunca datos de tarjeta
            $table->string('origen', 20)->default('POS'); // POS | LEGACY
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['venta_id', 'orden']);
            $table->index('sesion_caja_id');
        });

        // Restricciones de dominio en PostgreSQL:
        // 1) Todo pago debe aplicar un monto positivo.
        DB::statement('
            ALTER TABLE pagos_venta
            ADD CONSTRAINT pagos_venta_monto_aplicado_check
            CHECK (monto_aplicado > 0)
        ');

        // 2) Estrictos para pagos operacionales (origen POS): EFECTIVO exige
        //    recibido y cambio (no negativos) y el cambio exacto es
        //    recibido - aplicado; métodos no efectivo no tocan el cajón físico.
        //    Los pagos LEGACY (backfill histórico) conservan nulos para no
        //    inventar evidencia que nunca existió.
        DB::statement("
            ALTER TABLE pagos_venta
            ADD CONSTRAINT pagos_venta_efectivo_check
            CHECK (
                origen = 'LEGACY'
                OR (
                    (metodo = 'EFECTIVO'
                        AND efectivo_recibido IS NOT NULL
                        AND cambio_entregado IS NOT NULL
                        AND efectivo_recibido >= monto_aplicado
                        AND cambio_entregado = efectivo_recibido - monto_aplicado)
                 OR
                    (metodo <> 'EFECTIVO'
                        AND efectivo_recibido IS NULL
                        AND cambio_entregado IS NULL)
                )
            )
        ");

        // 3) Métodos soportados B14 (CREDITO reservado para B15, no activo).
        DB::statement("
            ALTER TABLE pagos_venta
            ADD CONSTRAINT pagos_venta_metodo_check
            CHECK (metodo IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'CREDITO'))
        ");

        // 4) Origen de los datos del pago.
        DB::statement("
            ALTER TABLE pagos_venta
            ADD CONSTRAINT pagos_venta_origen_check
            CHECK (origen IN ('POS', 'LEGACY'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_venta');
    }
};
