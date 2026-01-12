<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Asegurar que existan tablas/columna antes
        if (!Schema::hasTable('items') || !Schema::hasTable('ubicaciones')) {
            return;
        }
        if (!Schema::hasColumn('items', 'ubicacion_id')) {
            return;
        }

        // Crear FK solo si no existe (PostgreSQL)
        $exists = DB::selectOne("
            select 1
            from pg_constraint
            where conname = 'items_ubicacion_id_foreign'
            limit 1
        ");

        if (!$exists) {
            Schema::table('items', function (Blueprint $table) {
                $table->foreign('ubicacion_id', 'items_ubicacion_id_foreign')
                    ->references('id')->on('ubicaciones')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('items')) {
            return;
        }

        // Dropear solo si existe
        $exists = DB::selectOne("
            select 1
            from pg_constraint
            where conname = 'items_ubicacion_id_foreign'
            limit 1
        ");

        if ($exists) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropForeign('items_ubicacion_id_foreign');
            });
        }
    }
};
