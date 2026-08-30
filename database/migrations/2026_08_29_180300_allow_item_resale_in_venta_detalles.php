<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — Permitir reventa legítima de un Item tras cancelación/devolución.
 *
 * La premisa original "1 Item = máximo 1 Venta confirmada" (UNIQUE global sobre
 * item_id) quedó obsoleta: B11 habilita VENDIDO -> DISPONIBLE (cancelación) y
 * VENDIDO -> DEVUELTO -> DISPONIBLE (devolución + cambio autorizado), por lo que
 * un Item puede tener varias VENTAS HISTÓRICAS en su vida, aunque solo pueda
 * estar en una venta ACTIVA al mismo tiempo.
 *
 * Nuevo diseño:
 *   - UNIQUE(venta_id, item_id): un Item nunca aparece dos veces en la MISMA venta.
 *   - INDEX(item_id) no único: consultas históricas por Item.
 *   - VentaDetalle histórico nunca se borra; cada venta mantiene su precio.
 */
return new class extends Migration
{
    private const UNIQUE_GLOBAL = 'venta_detalles_item_id_unique';

    private const INDEX_ITEM_ID = 'venta_detalles_item_id_index';

    private const UNIQUE_VENTA_ITEM = 'venta_detalles_venta_id_item_id_unique';

    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_GLOBAL);

            $table->index('item_id', self::INDEX_ITEM_ID);

            $table->unique(['venta_id', 'item_id'], self::UNIQUE_VENTA_ITEM);
        });
    }

    public function down(): void
    {
        if ($this->existenReventasReales()) {
            throw new RuntimeException(
                'No se puede revertir esta migración: existen venta_detalles con el mismo '.
                'item_id en ventas distintas (reventa legítima ya registrada). Restaurar '.
                'UNIQUE(item_id) rompería el historial y el índice haría fallar cualquier '.
                'inserción posterior. El rollback se aborta sin borrar datos.'
            );
        }

        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_VENTA_ITEM);
            $table->dropIndex(self::INDEX_ITEM_ID);
        });

        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->unique('item_id', self::UNIQUE_GLOBAL);
        });
    }

    private function existenReventasReales(): bool
    {
        $row = DB::selectOne('
            SELECT count(*) AS total
            FROM (
                SELECT item_id
                FROM venta_detalles
                GROUP BY item_id
                HAVING count(*) > 1
            ) d
        ');

        return (int) ($row->total ?? 0) > 0;
    }
};
