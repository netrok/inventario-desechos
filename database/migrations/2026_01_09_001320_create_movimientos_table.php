<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('items')
                ->cascadeOnDelete();

            $table->string('tipo', 30)->index();
            // ALTA | CAMBIO_ESTADO | CAMBIO_UBICACION | NOTA | BAJA | VENTA | RESERVA | ENTREGA

            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30)->nullable();

            $table->foreignId('ubicacion_anterior_id')->nullable()
                ->constrained('ubicaciones')
                ->nullOnDelete();

            $table->foreignId('ubicacion_nueva_id')->nullable()
                ->constrained('ubicaciones')
                ->nullOnDelete();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('detalle')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
