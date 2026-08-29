<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'ventas_estado_check';

    private const ESTADOS = ['ACTIVA', 'PARCIALMENTE_DEVUELTA', 'DEVUELTA', 'CANCELADA'];

    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('estado', 30)->default('ACTIVA')->index();
        });

        // Las ventas existentes quedan ACTIVA por diseño (migración segura).
        DB::statement('UPDATE ventas SET estado = '.self::safeQuote('ACTIVA').' WHERE estado IS NULL OR estado = '.self::safeQuote(''));

        DB::statement(
            'ALTER TABLE ventas ADD CONSTRAINT '.self::CONSTRAINT.
            ' CHECK (estado IN ('.implode(', ', array_map([self::class, 'safeQuote'], self::ESTADOS)).'))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('estado');
        });
    }

    private static function safeQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
