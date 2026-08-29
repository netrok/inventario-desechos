<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'clientes_codigo_seq_generator';

    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();

            // Código único CLI-XXXXXX (sequence PostgreSQL, concurrency-safe).
            $table->string('codigo', 20)->unique();

            // PERSONA | EMPRESA
            $table->string('tipo', 20)->default('PERSONA');

            $table->string('nombre');
            $table->string('rfc', 20)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('direccion')->nullable();
            $table->text('notas')->nullable();

            // Ciclo de vida: ACTIVO / INACTIVO. No hay borrado físico.
            $table->boolean('activo')->default(true);

            $table->timestamps();

            // Índices útiles para búsqueda/autocomplete del POS y catálogo.
            $table->index('nombre');
            $table->index('rfc');
            $table->index('telefono');
            $table->index('email');
            $table->index('activo');
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT COALESCE(
                    (SELECT MAX(NULLIF(substring(codigo FROM '^CLI-([0-9]+)\$'), '')::bigint) FROM clientes),
                    0
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        Schema::dropIfExists('clientes');
    }
};
