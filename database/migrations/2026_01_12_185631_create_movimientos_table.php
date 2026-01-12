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

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('tipo', 50); // ALTA, CAMBIO_ESTADO, MOVER, BAJA, etc.

            $table->string('de_estado', 30)->nullable();
            $table->string('a_estado', 30)->nullable();

            $table->foreignId('de_ubicacion_id')
                ->nullable()
                ->constrained('ubicaciones')
                ->nullOnDelete();

            $table->foreignId('a_ubicacion_id')
                ->nullable()
                ->constrained('ubicaciones')
                ->nullOnDelete();

            $table->text('notas')->nullable();
            $table->string('evidencia_path')->nullable();

            $table->timestamp('fecha')->useCurrent()->index();

            $table->timestamps();

            $table->index(['item_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
