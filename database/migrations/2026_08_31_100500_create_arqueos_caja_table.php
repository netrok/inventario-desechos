<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arqueos de caja. Un único arqueo FINAL por sesión (índice único parcial),
     * realizado bajo corte ciego para el operador.
     */
    public function up(): void
    {
        Schema::create('arqueos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_caja_id')->constrained('sesiones_caja')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('tipo', 20)->default('FINAL');
            $table->decimal('efectivo_contado', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('sesion_caja_id');
        });

        DB::statement("
            ALTER TABLE arqueos_caja
            ADD CONSTRAINT arqueos_caja_tipo_check
            CHECK (tipo IN ('FINAL'))
        ");

        DB::statement('
            ALTER TABLE arqueos_caja
            ADD CONSTRAINT arqueos_caja_contado_check
            CHECK (efectivo_contado >= 0)
        ');

        DB::statement("
            CREATE UNIQUE INDEX arqueos_caja_final_sesion_unique
            ON arqueos_caja (sesion_caja_id)
            WHERE tipo = 'FINAL'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueos_caja');
    }
};
