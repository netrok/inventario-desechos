<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movimientos FÍSICOS de efectivo del cajón. Nunca pagos electrónicos.
     * Solo registro (sin update operacional ni delete), evidenciado además
     * por la ausencia de updated_at y FKs RESTRICT.
     */
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_caja_id')->constrained('sesiones_caja')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->decimal('monto', 12, 2);
            $table->foreignId('venta_id')->nullable()->constrained('ventas')->restrictOnDelete();
            $table->foreignId('pago_venta_id')->nullable()->constrained('pagos_venta')->restrictOnDelete();
            $table->foreignId('documento_postventa_id')->nullable()->constrained('documentos_postventa')->restrictOnDelete();
            $table->string('concepto', 255);
            $table->string('referencia', 100)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['sesion_caja_id', 'tipo']);
            $table->index('tipo');
        });

        // monto siempre positivo; la dirección la determina el tipo igual que
        // el signo en el cálculo del efectivo esperado (entrada vs salida).
        DB::statement('
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_monto_check
            CHECK (monto > 0)
        ');

        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_check
            CHECK (tipo IN (
                'COBRO_EFECTIVO',
                'CAMBIO_ENTREGADO',
                'ENTRADA_MANUAL',
                'RETIRO',
                'REEMBOLSO_EFECTIVO',
                'AJUSTE'
            ))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
