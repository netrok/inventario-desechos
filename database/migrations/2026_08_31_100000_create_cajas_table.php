<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'cajas_codigo_seq_generator';

    /**
     * Bloque B14: entidad caja física/lógica. Sin borrado operacional
     * (una caja con sesiones históricas nunca se elimina).
     */
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique(); // CAJ-000001 (sequence, concurrency-safe)
            $table->string('nombre', 100);
            $table->boolean('activa')->default(true);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT COALESCE(
                    (SELECT MAX(NULLIF(substring(codigo FROM '^CAJ-([0-9]+)\$'), '')::bigint) FROM cajas),
                    0
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        Schema::dropIfExists('cajas');
    }
};
