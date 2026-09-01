<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.4 — Idempotencia de ABONOS.
     *
     * Agrega operacion_uuid (UUID NULL) a movimientos_cxc para que un endpoint
     * financiero no duplique un abono por doble click / refresh / retry HTTP.
     *
     * - El formulario de ABONO manda un UUID generado server-side.
     * - El servicio trata el mismo UUID como operación idempotente.
     * - La protección existe en BD además de la UI: índice UNIQUE parcial
     *   sobre operacion_uuid cuando NO es NULL (en PostgreSQL los NULL no
     *   colisionan, así que las filas históricas sin UUID siguen permitidas).
     * - NO se hace operacion_uuid obligatorio para filas históricas.
     */
    public function up(): void
    {
        Schema::table('movimientos_cxc', function ($table) {
            $table->uuid('operacion_uuid')->nullable()->after('observaciones');
        });

        // UNIQUE parcial real de PostgreSQL. Laravel 12 PostgresGrammar no
        // compila el WHERE de un IndexDefinition, por lo que se crea con SQL.
        DB::statement('
            CREATE UNIQUE INDEX mxc_operacion_uuid_unico
            ON movimientos_cxc (operacion_uuid)
            WHERE operacion_uuid IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mxc_operacion_uuid_unico');

        Schema::table('movimientos_cxc', function ($table) {
            $table->dropColumn('operacion_uuid');
        });
    }
};
