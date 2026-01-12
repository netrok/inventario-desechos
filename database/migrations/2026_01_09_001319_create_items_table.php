<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 40)->unique(); // interno: ITM-000001
            $table->string('serie', 120)->nullable()->index();
            $table->string('marca', 80)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('categoria', 80)->nullable(); // luego lo hacemos FK si quieres

            $table->string('estado', 30)->default('DISPONIBLE')->index();
            // DISPONIBLE | RESERVADO | VENDIDO | BAJA | REPARACION

            $table->foreignId('ubicacion_id')->nullable()
                ->constrained('ubicaciones')
                ->nullOnDelete();

            $table->text('notas')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
