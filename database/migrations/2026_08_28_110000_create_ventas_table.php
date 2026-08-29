<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'ventas_folio_seq_generator';

    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();

            $table->string('folio', 20)->unique(); // VTA-000001 (sequence, concurrency-safe)

            // Actor obligatorio: toda venta (POS) requiere usuario autenticado.
            // RESTRICT: el actor histórico de una venta no puede desaparecer.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->decimal('total', 12, 2);
            $table->string('forma_pago', 20); // EFECTIVO, TARJETA, TRANSFERENCIA, OTRO
            $table->text('notas')->nullable();

            $table->timestamps();
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT COALESCE(
                    (SELECT MAX(NULLIF(substring(folio FROM '^VTA-([0-9]+)\$'), '')::bigint) FROM ventas),
                    0
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        Schema::dropIfExists('ventas');
    }
};
