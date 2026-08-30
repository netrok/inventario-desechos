<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'sesiones_caja_folio_seq_generator';

    /**
     * Sesiones de caja: una caja física no puede tener dos sesiones ABIERTAS a la vez
     * (reforzado con índice único parcial). Una sesión CERRADA es inmutable.
     */
    public function up(): void
    {
        Schema::create('sesiones_caja', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique(); // COR-000001 (sequence, concurrency-safe)
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->foreignId('user_id_apertura')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id_cierre')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at')->useCurrent()->index();
            $table->decimal('fondo_inicial', 12, 2);
            $table->string('estado', 20)->default('ABIERTA')->index();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('efectivo_contado', 12, 2)->nullable();
            $table->decimal('efectivo_esperado', 12, 2)->nullable();
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->text('observaciones_apertura')->nullable();
            $table->text('observaciones_cierre')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT COALESCE(
                    (SELECT MAX(NULLIF(substring(folio FROM '^COR-([0-9]+)\$'), '')::bigint) FROM sesiones_caja),
                    0
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");

        // Reglas de dominio a nivel BD (no solo Laravel):
        // 1) Una caja física no puede tener dos sesiones ABIERTAS simultáneas.
        DB::statement("
            CREATE UNIQUE INDEX sesiones_caja_caja_abierta_unique
            ON sesiones_caja (caja_id)
            WHERE estado = 'ABIERTA'
        ");

        // 2) Un operador no puede operar dos cajas abiertas a la vez.
        DB::statement("
            CREATE UNIQUE INDEX sesiones_caja_operador_abierta_unique
            ON sesiones_caja (user_id_apertura)
            WHERE estado = 'ABIERTA'
        ");

        DB::statement("
            ALTER TABLE sesiones_caja
            ADD CONSTRAINT sesiones_caja_estado_check
            CHECK (estado IN ('ABIERTA', 'CERRADA'))
        ");

        DB::statement('
            ALTER TABLE sesiones_caja
            ADD CONSTRAINT sesiones_caja_fondo_check
            CHECK (fondo_inicial >= 0)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        Schema::dropIfExists('sesiones_caja');
    }
};
