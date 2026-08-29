<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FKs que conservan la trazabilidad histórica de movimientos.
     *
     * - movimientos.user_id        → RESTRICT (conservar el actor histórico)
     * - movimientos.de_ubicacion_id → RESTRICT (conservar ubicación previa)
     * - movimientos.a_ubicacion_id  → RESTRICT (conservar ubicación nueva)
     *
     * Las columnas permanecen nullable por compatibilidad con registros legacy
     * (movimientos sin actor / sin ubicaciones registradas).
     */
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            foreach ($this->constraints() as $column) {
                $this->dropConstraintIfExists($table, $column);
                $table->foreign($column, 'movimientos_'.$column.'_foreign')
                    ->references('id')
                    ->on($this->referenceTable($column))
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            foreach ($this->constraints() as $column) {
                $this->dropConstraintIfExists($table, $column);
                $table->foreign($column, 'movimientos_'.$column.'_foreign')
                    ->references('id')
                    ->on($this->referenceTable($column))
                    ->nullOnDelete();
            }
        });
    }

    private function constraints(): array
    {
        return ['user_id', 'de_ubicacion_id', 'a_ubicacion_id'];
    }

    private function referenceTable(string $column): string
    {
        return $column === 'user_id' ? 'users' : 'ubicaciones';
    }

    private function dropConstraintIfExists(Blueprint $table, string $column): void
    {
        $name = 'movimientos_'.$column.'_foreign';

        $exists = DB::selectOne(
            'select 1
             from pg_constraint
             where conname = :name
             limit 1',
            ['name' => $name]
        );

        if ($exists) {
            $table->dropForeign($name);
        }
    }
};
