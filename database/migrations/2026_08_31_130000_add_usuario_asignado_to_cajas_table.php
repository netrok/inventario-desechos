<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B14.3.1 — Asignación de operador a caja.
     *
     * Una caja física tiene UN operador actualmente asignado. El usuario
     * autenticado solo puede abrir su caja asignada.
     *
     * usuario_asignado_id: quién está autorizado actualmente. NULL hasta que
     * el Admin la asigne (PostgreSQL permite múltiples NULL, por lo que las
     * cajas inactivas/no asignadas coexisten). UNIQUE garantiza que un usuario
     * no quede asignado simultáneamente a dos cajas. FK RESTRICT impide borrar
     * un usuario todavía asignado.
     *
     * B14.3.1 FIX 3 — CHECK "cajas_activa_requiere_operador": una caja ACTIVA
     * debe tener operador asignado. Se crea NOT VALID (no escanea la tabla
     * existente, en la que las cajas activas históricas quedan NULL) y la
     * validación autoritativa se apoya además en el controlador.
     *
     * NO se inventa asignación para cajas existentes: quedan NULL.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->unsignedBigInteger('usuario_asignado_id')->nullable()->after('activa');

            $table->foreign('usuario_asignado_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->unique('usuario_asignado_id');
        });

        // PostgreSQL: constrain activas -> opcional-requerido, sin escanear la
        // tabla existente (NOT VALID). Solo PostgreSQL soporta NOT VALID.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cajas ADD CONSTRAINT cajas_activa_requiere_operador CHECK (NOT activa OR usuario_asignado_id IS NOT NULL) NOT VALID');
        }
    }

    public function down(): void
    {
        // Quitar el CHECK ANTES de eliminar la columna (depende de ella).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cajas DROP CONSTRAINT IF EXISTS cajas_activa_requiere_operador');
        }

        Schema::table('cajas', function (Blueprint $table) {
            $table->dropUnique(['usuario_asignado_id']);
            $table->dropForeign(['usuario_asignado_id']);
            $table->dropColumn('usuario_asignado_id');
        });
    }
};
