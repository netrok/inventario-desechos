<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.4 — Vinculación MovimientoCaja <-> MovimientoCxC.
     *
     * 1) Nuevos tipos físicos de caja para cobranza:
     *      ABONO_CXC_EFECTIVO  (ENTRADA)
     *      REVERSA_CXC_EFECTIVO (SALIDA)
     *    Ambos caben en VARCHAR(20); NO se ensancha el tipo.
     *
     * 2) movimiento_caja.movimiento_cxc_id (nullable) + FK RESTRICT + UNIQUE:
     *    máximo un movimiento físico de caja por movimiento económico CxC
     *    (los NULL no colisionan en el índice UNIQUE de PostgreSQL).
     *
     * 3) Se actualizan los CHECK existentes (tipo y tipo/dirección) para
     *    admitir los nuevos tipos con su dirección fija.
     *
     * 4) Trigger declarativo mvcaja_cxc_vinculo que hace imposible un vínculo
     *    incoherente a nivel de BD:
     *      - ABONO_CXC_EFECTIVO   -> debe ligarse a un MovimientoCxC ABONO
     *                                de método EFECTIVO con monto idéntico.
     *      - REVERSA_CXC_EFECTIVO -> debe ligarse a una REVERSA_ABONO cuyo
     *                                ABONO origen fue EFECTIVO, monto idéntico.
     *      - cualquier otro tipo  -> movimiento_cxc_id DEBE ser NULL.
     *
     * La migración es reversible y restaura los constraints previos en down().
     */
    public function up(): void
    {
        Schema::table('movimientos_caja', function ($table) {
            $table->bigInteger('movimiento_cxc_id')->nullable()->after('documento_postventa_id');

            $table->foreign('movimiento_cxc_id')
                ->references('id')
                ->on('movimientos_cxc')
                ->restrictOnDelete();
        });

        // Máximo un movimiento físico de caja por movimiento económico CxC.
        DB::statement('
            CREATE UNIQUE INDEX movimientos_caja_movimiento_cxc_unico
            ON movimientos_caja (movimiento_cxc_id)
            WHERE movimiento_cxc_id IS NOT NULL
        ');

        // Reconstruir el CHECK de tipo admitiendo los nuevos tipos de cobranza.
        DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_check');
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_check
            CHECK (tipo IN (
                'COBRO_EFECTIVO',
                'CAMBIO_ENTREGADO',
                'ENTRADA_MANUAL',
                'RETIRO',
                'REEMBOLSO_EFECTIVO',
                'AJUSTE',
                'ABONO_CXC_EFECTIVO',
                'REVERSA_CXC_EFECTIVO'
            ))
        ");

        // Reconstruir el CHECK tipo/dirección con las nuevas direcciones fijas.
        DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_direccion_check');
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_direccion_check
            CHECK (
                (tipo = 'COBRO_EFECTIVO'           AND direccion = 'ENTRADA')
             OR (tipo = 'ENTRADA_MANUAL'           AND direccion = 'ENTRADA')
             OR (tipo = 'ABONO_CXC_EFECTIVO'       AND direccion = 'ENTRADA')
             OR (tipo = 'CAMBIO_ENTREGADO'         AND direccion = 'SALIDA')
             OR (tipo = 'RETIRO'                   AND direccion = 'SALIDA')
             OR (tipo = 'REEMBOLSO_EFECTIVO'       AND direccion = 'SALIDA')
             OR (tipo = 'REVERSA_CXC_EFECTIVO'     AND direccion = 'SALIDA')
             OR (tipo = 'AJUSTE'                   AND direccion IN ('ENTRADA', 'SALIDA'))
            )
        ");

        // Barrera BD del vínculo CxC <-> Caja (declarativo, PostgreSQL).
        DB::statement('
            CREATE OR REPLACE FUNCTION mvcaja_cxc_vinculo_fn() RETURNS trigger AS $$
            DECLARE
                mx RECORD;
                orig RECORD;
            BEGIN
                IF NEW.tipo = \'ABONO_CXC_EFECTIVO\' THEN
                    IF NEW.movimiento_cxc_id IS NULL THEN
                        RAISE EXCEPTION \'ABONO_CXC_EFECTIVO requiere movimiento_cxc_id.\';
                    END IF;
                    SELECT tipo, metodo, monto_centavos, cuenta_por_cobrar_id
                    INTO mx
                    FROM movimientos_cxc
                    WHERE id = NEW.movimiento_cxc_id;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION \'El movimiento CxC vinculado no existe.\';
                    END IF;
                    IF mx.tipo <> \'ABONO\' OR mx.metodo <> \'EFECTIVO\' THEN
                        RAISE EXCEPTION \'ABONO_CXC_EFECTIVO solo puede ligarse a un ABONO de método EFECTIVO.\';
                    END IF;
                    IF mx.monto_centavos IS DISTINCT FROM (NEW.monto * 100)::bigint THEN
                        RAISE EXCEPTION \'El importe de caja debe coincidir con el monto del ABONO CxC.\';
                    END IF;
                ELSIF NEW.tipo = \'REVERSA_CXC_EFECTIVO\' THEN
                    IF NEW.movimiento_cxc_id IS NULL THEN
                        RAISE EXCEPTION \'REVERSA_CXC_EFECTIVO requiere movimiento_cxc_id.\';
                    END IF;
                    SELECT tipo, monto_centavos, movimiento_origen_id, cuenta_por_cobrar_id
                    INTO mx
                    FROM movimientos_cxc
                    WHERE id = NEW.movimiento_cxc_id;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION \'El movimiento CxC vinculado no existe.\';
                    END IF;
                    IF mx.tipo <> \'REVERSA_ABONO\' THEN
                        RAISE EXCEPTION \'REVERSA_CXC_EFECTIVO solo puede ligarse a una REVERSA_ABONO.\';
                    END IF;
                    SELECT tipo, metodo, monto_centavos
                    INTO orig
                    FROM movimientos_cxc
                    WHERE id = mx.movimiento_origen_id;
                    IF NOT FOUND OR orig.tipo <> \'ABONO\' OR orig.metodo <> \'EFECTIVO\' THEN
                        RAISE EXCEPTION \'La REVERSA_ABONO vinculada debe tener un ABONO origen EFECTIVO.\';
                    END IF;
                    IF mx.monto_centavos IS DISTINCT FROM (NEW.monto * 100)::bigint THEN
                        RAISE EXCEPTION \'El importe de caja debe coincidir con el monto de la REVERSA CxC.\';
                    END IF;
                ELSE
                    IF NEW.movimiento_cxc_id IS NOT NULL THEN
                        RAISE EXCEPTION \'Solo ABONO_CXC_EFECTIVO y REVERSA_CXC_EFECTIVO pueden vincularse a un movimiento CxC.\';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
        ');

        DB::statement('
            DROP TRIGGER IF EXISTS mvcaja_cxc_vinculo ON movimientos_caja
        ');

        DB::statement('
            CREATE TRIGGER mvcaja_cxc_vinculo
            BEFORE INSERT OR UPDATE OF tipo, movimiento_cxc_id, monto ON movimientos_caja
            FOR EACH ROW EXECUTE FUNCTION mvcaja_cxc_vinculo_fn()
        ');
    }

    /**
     * Reversión real con datos B15.4 presentes.
     *
     * NOTA DE PRESERVACIÓN: el rollback conserva el EFECTO FÍSICO de caja
     * (monto, sesión, user, concepto, referencia, created_at, dirección) pero
     * PIERDE el vínculo/tipo semántico B15.4 (ABONO_CXC_EFECTIVO / REVERSA_
     * CXC_EFECTIVO y movimiento_cxc_id) porque la versión anterior de la app no
     * conoce CxC en Caja. NO se borra ningún movimiento; el ledger económico
     * (movimientos_cxc) permanece intacto y append-only.
     *
     * Orden estricto:
     *   1) DROP del trigger/function B15.4 (para que los UPDATE de degradación
     *      no disparen la validación de vínculo).
     *   2) DEGRADAR explícitamente las filas B15.4 que ya existen:
     *        ABONO_CXC_EFECTIVO  -> ENTRADA_MANUAL (direccion se mantiene ENTRADA)
     *        REVERSA_CXC_EFECTIVO -> RETIRO        (direccion se mantiene SALIDA)
     *      y poner movimiento_cxc_id = NULL (el vínculo semántico no existe en la
     *      versión anterior; las filas físicas se conservan íntegras).
     *   3) DROP del índice UNIQUE parcial y de la FK/columna.
     *   4) Restaurar EXACTAMENTE los CHECKs B14 (sin los tipos de cobranza);
     *      ya es seguro porque en este punto no quedan filas con los tipos
     *      degradados.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS mvcaja_cxc_vinculo ON movimientos_caja');
        DB::statement('DROP FUNCTION IF EXISTS mvcaja_cxc_vinculo_fn');

        // Degradación de filas B15.4 -> tipos B14 previos, sin borrarlas.
        DB::statement("
            UPDATE movimientos_caja
            SET tipo = 'ENTRADA_MANUAL', movimiento_cxc_id = NULL
            WHERE tipo = 'ABONO_CXC_EFECTIVO'
        ");
        DB::statement("
            UPDATE movimientos_caja
            SET tipo = 'RETIRO', movimiento_cxc_id = NULL
            WHERE tipo = 'REVERSA_CXC_EFECTIVO'
        ");

        DB::statement('DROP INDEX IF EXISTS movimientos_caja_movimiento_cxc_unico');

        Schema::table('movimientos_caja', function ($table) {
            $table->dropForeign(['movimiento_cxc_id']);
            $table->dropColumn('movimiento_cxc_id');
        });

        // Restaurar los CHECK originales B14 (sin los tipos de cobranza).
        DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_check');
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_check
            CHECK (tipo IN (
                'COBRO_EFECTIVO',
                'CAMBIO_ENTREGADO',
                'ENTRADA_MANUAL',
                'RETIRO',
                'REEMBOLSO_EFECTIVO',
                'AJUSTE'
            ))
        ");

        DB::statement('ALTER TABLE movimientos_caja DROP CONSTRAINT IF EXISTS movimientos_caja_tipo_direccion_check');
        DB::statement("
            ALTER TABLE movimientos_caja
            ADD CONSTRAINT movimientos_caja_tipo_direccion_check
            CHECK (
                (tipo = 'COBRO_EFECTIVO'      AND direccion = 'ENTRADA')
             OR (tipo = 'ENTRADA_MANUAL'      AND direccion = 'ENTRADA')
             OR (tipo = 'CAMBIO_ENTREGADO'    AND direccion = 'SALIDA')
             OR (tipo = 'RETIRO'              AND direccion = 'SALIDA')
             OR (tipo = 'REEMBOLSO_EFECTIVO'  AND direccion = 'SALIDA')
             OR (tipo = 'AJUSTE'              AND direccion IN ('ENTRADA', 'SALIDA'))
            )
        ");
    }
};
