<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documento_postventa_detalles', function (Blueprint $table) {
            $table->id();

            // El documento postventa nunca se borra: RESTRICT protege el historial.
            $table->foreignId('documento_postventa_id')
                ->constrained('documentos_postventa')
                ->restrictOnDelete();

            // UNIQUE(venta_detalle_id): un detalle de venta NO puede devolverse
            // ni cancelarse dos veces en ningún documento postventa.
            $table->foreignId('venta_detalle_id')
                ->unique()
                ->constrained('venta_detalles')
                ->restrictOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            // Importe histórico server-side (VentaDetalle.precio), nunca del navegador.
            $table->decimal('importe', 12, 2);

            $table->timestamps();

            $table->index('documento_postventa_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_postventa_detalles');
    }
};
