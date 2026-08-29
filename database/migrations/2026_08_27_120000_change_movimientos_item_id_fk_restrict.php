<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'movimientos_item_id_foreign';

    /**
     * Cambia la FK de movimientos.item_id de CASCADE a RESTRICT.
     *
     * Objetivo: impedir que un borrado físico de un Item (forceDelete)
     * destruya su historial de movimientos (trazabilidad).
     */
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $this->dropConstraintIfExists($table);

            $table->foreign('item_id', self::CONSTRAINT)
                ->references('id')
                ->on('items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $this->dropConstraintIfExists($table);

            $table->foreign('item_id', self::CONSTRAINT)
                ->references('id')
                ->on('items')
                ->cascadeOnDelete();
        });
    }

    private function dropConstraintIfExists(Blueprint $table): void
    {
        $exists = DB::selectOne(
            'select 1
             from pg_constraint
             where conname = :name
             limit 1',
            ['name' => self::CONSTRAINT]
        );

        if ($exists) {
            $table->dropForeign(self::CONSTRAINT);
        }
    }
};
