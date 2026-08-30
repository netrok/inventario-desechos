<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pertenece al bloque B13 (revisión formal de artículos devueltos).
     * Tabla incremental: no toca migraciones previas. Sin soft-delete por ser
     * evidencia histórico-administrativa de la trazabilidad post-venta.
     */
    public function up(): void
    {
        Schema::create('revisiones_devolucion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('documento_postventa_detalle_id')
                ->unique()
                ->constrained('documento_postventa_detalles')
                ->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('resultado', 20);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index('item_id');
        });

        // Constraint de dominio en PostgreSQL: solo admite resultados de revisión
        // que sean estados finales válidos del Item (DISPONIBLE/REPARACION/BAJA).
        DB::statement(
            "ALTER TABLE revisiones_devolucion
             ADD CONSTRAINT revisiones_devolucion_resultado_check
             CHECK (resultado IN ('DISPONIBLE', 'REPARACION', 'BAJA'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('revisiones_devolucion');
    }
};
