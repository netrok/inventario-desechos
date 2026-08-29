<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SEQUENCE = 'documentos_postventa_folio_seq_generator';

    private const TIPOS = ['CANCELACION', 'DEVOLUCION'];

    private const FORMAS_REEMBOLSO = ['EFECTIVO', 'TARJETA', 'TRANSFERENCIA', 'OTRO'];

    public function up(): void
    {
        Schema::create('documentos_postventa', function (Blueprint $table) {
            $table->id();

            // Folio DEV-XXXXXX desde sequence (concurrency-safe).
            $table->string('folio', 20)->unique();

            // La venta original SIEMPRE se conserva (FK RESTRICT).
            $table->foreignId('venta_id')
                ->constrained('ventas')
                ->restrictOnDelete();

            $table->string('tipo', 20); // CANCELACION | DEVOLUCION

            // Actor obligatorio: toda operación postventa requiere usuario autenticado.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('motivo');

            $table->string('forma_reembolso', 20)->nullable();

            $table->decimal('total', 12, 2);

            $table->timestamps();

            $table->index('venta_id');
            $table->index('user_id');
        });

        DB::statement(
            'ALTER TABLE documentos_postventa ADD CONSTRAINT documentos_postventa_tipo_check'.
            ' CHECK (tipo IN ('.implode(', ', array_map([self::class, 'quote'], self::TIPOS)).'))'
        );

        DB::statement(
            'ALTER TABLE documentos_postventa ADD CONSTRAINT documentos_postventa_forma_reembolso_check'.
            ' CHECK (forma_reembolso IS NULL OR forma_reembolso IN ('.
            implode(', ', array_map([self::class, 'quote'], self::FORMAS_REEMBOLSO)).'))'
        );

        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT COALESCE(
                    (SELECT MAX(NULLIF(substring(folio FROM '^DEV-([0-9]+)\$'), '')::bigint) FROM documentos_postventa),
                    0
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documentos_postventa DROP CONSTRAINT IF EXISTS documentos_postventa_tipo_check');
        DB::statement('ALTER TABLE documentos_postventa DROP CONSTRAINT IF EXISTS documentos_postventa_forma_reembolso_check');

        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
        Schema::dropIfExists('documentos_postventa');
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
