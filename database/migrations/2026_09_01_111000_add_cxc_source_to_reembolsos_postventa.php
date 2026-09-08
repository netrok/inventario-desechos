<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.5 — Fuente CxC de un reembolso postventa.
     *
     * Para ventas con CuentaPorCobrar el reembolso monetario usa PRIMERO los
     * ABONOS CxC más recientes (LIFO) y solo el resto se prorratea sobre los
     * PagoVenta originales (B14.2).
     *
     * 1) reembolsos_postventa.movimiento_cxc_id (nullable) + FK RESTRICT:
     *    el reembolso queda anclado al ABONO que lo financia. La relación con
     *    la caja física NO se introduce en movimientos_caja (B15.4 la reserva
     *    para ABONO_CXC_EFECTIVO / REVERSA_CXC_EFECTIVO); todo el vínculo vive
     *    aquí.
     *
     * 2) CHECK de origen ampliado: 'CXC_ABONO'.
     *
     * 3) Constraint de fuente EXACTA: cada origen exige su fuente y rechaza
     *    las demás (AUTOMATICO -> pago_venta_id; CXC_ABONO -> movimiento_cxc_id;
     *    LEGACY_MANUAL -> sin fuente).
     *
     * 4) Índice UNIQUE parcial por documento: máximo un reembolso CXC_ABONO
     *    por (documento, abono).
     *
     * 5) Trigger declarativo reembolso_fuente_exacta:
     *      - AUTOMATICO: el PagoVenta (lockeado FOR UPDATE) existe, pertenece a
     *        la misma venta, método idéntico y la suma acumulada de reembolsos
     *        sobre ese pago no supera su monto original.
     *      - CXC_ABONO: el ABONO (lockeado FOR UPDATE) existe, es tipo ABONO,
     *        no fue reversado, método idéntico, misma venta y la suma acumulada
     *        de reembolsos sobre ese abono no supera su monto.
     *
     * down() es real con datos: los reembolsos CXC_ABONO se conservan íntegros
     * (monto, método, documento, user, sesión, referencias, orden, timestamps)
     * degradando el origen a LEGACY_MANUAL con movimiento_cxc_id = NULL (la
     * versión anterior no conoce CxC en postventa), y se restauran los CHECK
     * previos B14.2.
     */
    public function up(): void
    {
        Schema::table('reembolsos_postventa', function ($table) {
            $table->foreignId('movimiento_cxc_id')
                ->nullable()
                ->after('pago_venta_id')
                ->constrained('movimientos_cxc')
                ->restrictOnDelete();
        });

        // Máximo un reembolso CXC_ABONO por (documento, abono).
        DB::statement('
            CREATE UNIQUE INDEX reembolsos_postventa_documento_abono_unique
            ON reembolsos_postventa (documento_postventa_id, movimiento_cxc_id)
            WHERE movimiento_cxc_id IS NOT NULL
        ');

        // Ampliar el CHECK de origen con CXC_ABONO.
        DB::statement('ALTER TABLE reembolsos_postventa DROP CONSTRAINT IF EXISTS reembolsos_postventa_origen_check');
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_check
            CHECK (origen IN ('AUTOMATICO', 'CXC_ABONO', 'LEGACY_MANUAL'))
        ");

        // Fuente exacta: cada origen exige su fuente y rechaza las demás.
        DB::statement('ALTER TABLE reembolsos_postventa DROP CONSTRAINT IF EXISTS reembolsos_postventa_origen_pago_check');
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_fuente_check
            CHECK (
                (origen = 'AUTOMATICO' AND pago_venta_id IS NOT NULL AND movimiento_cxc_id IS NULL)
                OR
                (origen = 'CXC_ABONO' AND pago_venta_id IS NULL AND movimiento_cxc_id IS NOT NULL)
                OR
                (origen = 'LEGACY_MANUAL' AND pago_venta_id IS NULL AND movimiento_cxc_id IS NULL)
            )
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION reembolso_fuente_exacta_fn() RETURNS trigger AS \$\$
            DECLARE
                abono  RECORD;
                abono_reversado BOOLEAN;
                cxc    RECORD;
                doc    RECORD;
                pago   RECORD;
                ya     numeric;
            BEGIN
                IF NEW.origen = 'LEGACY_MANUAL' THEN
                    RETURN NEW;
                END IF;

                SELECT venta_id
                INTO doc
                FROM documentos_postventa
                WHERE id = NEW.documento_postventa_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'El documento postventa del reembolso no existe.';
                END IF;

                IF NEW.origen = 'AUTOMATICO' THEN
                    SELECT venta_id, metodo, monto_aplicado
                    INTO pago
                    FROM pagos_venta
                    WHERE id = NEW.pago_venta_id
                    FOR UPDATE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'El pago original del reembolso no existe.';
                    END IF;

                    IF pago.venta_id IS DISTINCT FROM doc.venta_id THEN
                        RAISE EXCEPTION 'El pago del reembolso no pertenece a la venta del documento postventa.';
                    END IF;

                    IF pago.metodo IS DISTINCT FROM NEW.metodo THEN
                        RAISE EXCEPTION 'El método del reembolso no coincide con el pago original.';
                    END IF;

                    SELECT COALESCE(SUM(monto), 0)
                    INTO ya
                    FROM reembolsos_postventa
                    WHERE pago_venta_id = NEW.pago_venta_id
                      AND id IS DISTINCT FROM NEW.id;

                    IF (ya * 100)::bigint + (NEW.monto * 100)::bigint > (pago.monto_aplicado * 100)::bigint THEN
                        RAISE EXCEPTION 'El reembolso supera el monto original del pago.';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.origen = 'CXC_ABONO' THEN
                    SELECT id, tipo, metodo, monto_centavos, cuenta_por_cobrar_id
                    INTO abono
                    FROM movimientos_cxc
                    WHERE id = NEW.movimiento_cxc_id
                    FOR UPDATE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'El abono CxC vinculado al reembolso no existe.';
                    END IF;

                    IF abono.tipo <> 'ABONO' THEN
                        RAISE EXCEPTION 'El reembolso solo puede anclarse a un ABONO CxC.';
                    END IF;

                    SELECT EXISTS (
                        SELECT 1 FROM movimientos_cxc
                        WHERE tipo = 'REVERSA_ABONO'
                          AND movimiento_origen_id = abono.id
                    ) INTO abono_reversado;

                    IF abono_reversado THEN
                        RAISE EXCEPTION 'No puede reembolsarse un abono que ya fue reversado.';
                    END IF;

                    IF abono.metodo IS DISTINCT FROM NEW.metodo THEN
                        RAISE EXCEPTION 'El método del reembolso no coincide con el abono original.';
                    END IF;

                    SELECT venta_id
                    INTO cxc
                    FROM cuentas_por_cobrar
                    WHERE id = abono.cuenta_por_cobrar_id;

                    IF NOT FOUND OR cxc.venta_id IS DISTINCT FROM doc.venta_id THEN
                        RAISE EXCEPTION 'El abono del reembolso no pertenece a la venta del documento postventa.';
                    END IF;

                    SELECT COALESCE(SUM(monto), 0)
                    INTO ya
                    FROM reembolsos_postventa
                    WHERE movimiento_cxc_id = NEW.movimiento_cxc_id
                      AND id IS DISTINCT FROM NEW.id;

                    IF (ya * 100)::bigint + (NEW.monto * 100)::bigint > abono.monto_centavos THEN
                        RAISE EXCEPTION 'El reembolso supera el monto disponible del abono CxC.';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'El origen del reembolso no es válido.';
            END; \$\$ LANGUAGE plpgsql
        ");

        DB::statement('
            DROP TRIGGER IF EXISTS reembolso_fuente_exacta ON reembolsos_postventa
        ');

        DB::statement('
            CREATE TRIGGER reembolso_fuente_exacta
            BEFORE INSERT OR UPDATE OF
                documento_postventa_id, pago_venta_id, movimiento_cxc_id, metodo, monto, origen
            ON reembolsos_postventa
            FOR EACH ROW EXECUTE FUNCTION reembolso_fuente_exacta_fn()
        ');

        /*
         * Append-only: los reembolsos son históricos e inmutables.
         *
         * Mientras la política sea append-only este trigger es la barrera BD
         * definitiva (además de la barrera Eloquent). El down() lo elimina
         * ANTES de la degradación CXC_ABONO -> LEGACY_MANUAL para que ese
         * UPDATE de migración siga siendo posible.
         */
        DB::statement("
            CREATE OR REPLACE FUNCTION reembolsos_postventa_append_only_fn() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'Los reembolsos postventa son históricos e inmutables.';
            END; \$\$ LANGUAGE plpgsql
        ");

        DB::statement('
            DROP TRIGGER IF EXISTS reembolsos_postventa_append_only ON reembolsos_postventa
        ');

        DB::statement('
            CREATE TRIGGER reembolsos_postventa_append_only
            BEFORE UPDATE OR DELETE ON reembolsos_postventa
            FOR EACH ROW EXECUTE FUNCTION reembolsos_postventa_append_only_fn()
        ');
    }

    public function down(): void
    {
        // 1) append-only primero: permite el UPDATE de degradación posterior.
        DB::statement('DROP TRIGGER IF EXISTS reembolsos_postventa_append_only ON reembolsos_postventa');
        DB::statement('DROP FUNCTION IF EXISTS reembolsos_postventa_append_only_fn');

        // 2) fuente exacta.
        DB::statement('DROP TRIGGER IF EXISTS reembolso_fuente_exacta ON reembolsos_postventa');
        DB::statement('DROP FUNCTION IF EXISTS reembolso_fuente_exacta_fn');

        // 3) Degradación real con datos: el reembolso se conserva íntegro; solo
        //    pierde su vínculo semántico CxC (la versión anterior no lo conoce).
        DB::statement("
            UPDATE reembolsos_postventa
            SET origen = 'LEGACY_MANUAL', movimiento_cxc_id = NULL
            WHERE origen = 'CXC_ABONO'
        ");

        DB::statement('DROP INDEX IF EXISTS reembolsos_postventa_documento_abono_unique');

        Schema::table('reembolsos_postventa', function ($table) {
            $table->dropForeign(['movimiento_cxc_id']);
            $table->dropColumn('movimiento_cxc_id');
        });

        // Restaurar el CHECK de origen B14.2.
        DB::statement('ALTER TABLE reembolsos_postventa DROP CONSTRAINT IF EXISTS reembolsos_postventa_origen_check');
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_check
            CHECK (origen IN ('AUTOMATICO', 'LEGACY_MANUAL'))
        ");

        DB::statement('ALTER TABLE reembolsos_postventa DROP CONSTRAINT IF EXISTS reembolsos_postventa_origen_fuente_check');
        DB::statement("
            ALTER TABLE reembolsos_postventa
            ADD CONSTRAINT reembolsos_postventa_origen_pago_check
            CHECK (
                (origen = 'AUTOMATICO' AND pago_venta_id IS NOT NULL)
                OR
                (origen = 'LEGACY_MANUAL' AND pago_venta_id IS NULL)
            )
        ");
    }
};
