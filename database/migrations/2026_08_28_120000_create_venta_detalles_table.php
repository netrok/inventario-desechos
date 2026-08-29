<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_detalles', function (Blueprint $table) {
            $table->id();

            // Si se elimina la venta (no existe tal flujo), se llevan sus detalles.
            // UNIQUE(item_id): 1 Item = máximo 1 Venta confirmada (defensa a nivel BD).
            // Desde Item NO hay cascade: un detalle protege al item (FK RESTRICT).
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('item_id')->unique()->constrained('items')->restrictOnDelete();

            $table->decimal('precio', 12, 2);

            $table->timestamps();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_detalles');
    }
};
