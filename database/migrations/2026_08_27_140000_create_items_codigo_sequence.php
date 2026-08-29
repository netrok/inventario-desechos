<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEQUENCE = 'items_codigo_seq_generator';

    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE);

        DB::statement("
            WITH sync AS (
                SELECT GREATEST(
                    COALESCE((SELECT MAX(codigo_seq) FROM items), 0),
                    COALESCE((SELECT MAX(NULLIF(substring(codigo FROM '^ITM-([0-9]+)\$'), '')::bigint) FROM items), 0)
                ) AS max_real
            )
            SELECT setval('".self::SEQUENCE."', GREATEST(sync.max_real, 1), sync.max_real > 0) FROM sync
        ");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
    }
};
