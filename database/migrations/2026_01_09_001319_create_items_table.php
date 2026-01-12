<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 40)->unique(); // ITM-000001
            $table->string('serie', 120)->nullable()->index();
            $table->string('marca', 80)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('categoria', 80)->nullable(); // legacy temporal

            $table->string('estado', 30)->default('DISPONIBLE')->index();

            // ✅ SIN FK aquí (porque ubicaciones todavía no existe cuando corre esta migración)
            $table->foreignId('ubicacion_id')->nullable()->index();

            $table->text('notas')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
