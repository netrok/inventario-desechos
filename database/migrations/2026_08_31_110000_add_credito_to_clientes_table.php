<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.1
     *
     * Configuración administrativa de crédito por cliente (ADITIVA).
     *
     * - credito_habilitado: bool NOT NULL default false.
     * - limite_credito: decimal(12,2) NOT NULL default 0.
     * - dias_credito: integer NULL.
     *
     * Reglas de dominio (constraints PostgreSQL):
     *   1) Si el crédito está habilitado:
     *        limite_credito > 0
     *        Y dias_credito NO nulo Y > 0
     *   2) limite_credito >= 0 siempre.
     *   3) dias_credito, si no es nulo, debe ser > 0.
     *
     * Cuando credito_habilitado = false, limite_credito/dias_credito pueden
     * conservar valores configurados pero NO autorizan crédito (la decisión de
     * autorización vive en la capa de aplicación durante la venta).
     *
     * La migración es NO destructiva: no borra ni reconstruye clientes.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('credito_habilitado')->default(false);
            $table->decimal('limite_credito', 12, 2)->default(0);
            $table->integer('dias_credito')->nullable();
        });

        // limite_credito nunca negativo.
        DB::statement('
            ALTER TABLE clientes
            ADD CONSTRAINT clientes_limite_credito_non_negative
            CHECK (limite_credito >= 0)
        ');

        // dias_credito, cuando no es nulo, debe ser > 0.
        DB::statement('
            ALTER TABLE clientes
            ADD CONSTRAINT clientes_dias_credito_positive
            CHECK (dias_credito IS NULL OR dias_credito > 0)
        ');

        // Si el crédito está habilitado, exige límite > 0 y plazo > 0.
        DB::statement('
            ALTER TABLE clientes
            ADD CONSTRAINT clientes_credito_habilitado_requisitos
            CHECK (
                credito_habilitado = false
                OR (
                    limite_credito > 0
                    AND dias_credito IS NOT NULL
                    AND dias_credito > 0
                )
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_credito_habilitado_requisitos');
        DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_dias_credito_positive');
        DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_limite_credito_non_negative');

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['credito_habilitado', 'limite_credito', 'dias_credito']);
        });
    }
};
