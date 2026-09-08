<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Refuerza a nivel de base de datos la política de duplicados de
     * clientes (hallazgo #2 de la auditoría 2026-09-07). ClienteController
     * ya valida esto en la capa de aplicación; este índice es la red de
     * seguridad por si algún día se inserta un registro sin pasar por ahí
     * (tinker, un seeder, un import directo a la BD, etc.) — el mismo
     * criterio que ya se usa en el resto del proyecto para invariantes
     * críticas (ver el `codigo` de clientes, generado por secuencia).
     *
     * Son índices ÚNICOS PARCIALES (cláusula WHERE), no un `unique()` de
     * columna completa, porque:
     *
     * - El RFC genérico de "público en general" del SAT (XAXX010101000
     *   persona física, XEXX010101000 persona moral) se permite repetir
     *   a propósito: no identifica a un cliente real.
     * - Los NULL de rfc/email nunca deben chocar entre sí. Postgres ya
     *   trata cada NULL como distinto en un índice único normal, pero lo
     *   dejamos explícito con IS NOT NULL para que la intención quede
     *   clara para quien lea la migración.
     */
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX clientes_rfc_unique
            ON clientes (rfc)
            WHERE rfc IS NOT NULL
              AND rfc NOT IN ('XAXX010101000', 'XEXX010101000')
        ");

        DB::statement('
            CREATE UNIQUE INDEX clientes_email_unique
            ON clientes (email)
            WHERE email IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS clientes_rfc_unique');
        DB::statement('DROP INDEX IF EXISTS clientes_email_unique');
    }
};
