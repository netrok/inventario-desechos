<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.5 — Vínculo del ledger CxC con su documento postventa.
     *
     * 1) movimientos_cxc.documento_postventa_id (nullable) + FK RESTRICT:
     *    la deuda que extingue una postventa queda auditada a su documento.
     *
     * 2) CHECK mxc_documento_postventa_tipo_check: únicamente
     *    REDUCCION_POSTVENTA (devolución) y CANCELACION (cancelación)
     *    pueden llevar documento; el resto DEBE traer NULL.
     *
     * 3) Índice UNIQUE parcial mxc_documento_postventa_unico: máximo UNA
     *    reducción/cancelación de deuda por documento postventa.
     *
     * 4) Trigger declarativo mxc_vinculo_documento_postventa:
     *      - el documento existe,
     *      - la CxC y el documento pertenecen a la MISMA venta,
     *      - REDUCCION_POSTVENTA -> documento DEVOLUCION,
     *      - CANCELACION        -> documento CANCELACION,
     *      - el monto del movimiento no supera el total del documento.
     *
     * 5) Trigger declarativo mxc_reversa_bloquea_reembolso: un ABONO que ya
     *    fue utilizado en una operación postventa (existe un reembolso
     *    origen CXC_ABONO apuntando a él) NO puede reversarse a nivel BD.
     *    Esto complementa la barrera en CuentaPorCobrarService::reversarAbono.
     *
     * Reversible con datos: la columna es aditiva y no degrada el ledger; en
     * down() se conservan íntegros REDUCCION_POSTVENTA / CANCELACION y solo se
     * pierde el vínculo semántico con el documento (igual que B15.4).
     */
    public function up(): void
    {
        Schema::table('movimientos_cxc', function ($table) {
            $table->bigInteger('documento_postventa_id')->nullable()->after('movimiento_origen_id');

            $table->foreign('documento_postventa_id')
                ->references('id')
                ->on('documentos_postventa')
                ->restrictOnDelete();

            $table->index('documento_postventa_id');
        });

        // Únicamente la extinción de deuda puede llevar documento postventa; el
        // resto DEBE traer NULL. Los tipos de deuda pueden quedar sin documento
        // en un down/up con datos (el down() degrada el vínculo, no el ledger).
        DB::statement("
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_documento_postventa_tipo_check
            CHECK (
                documento_postventa_id IS NULL
                OR tipo IN ('REDUCCION_POSTVENTA', 'CANCELACION')
            )
        ");

        // Máximo un movimiento de deuda por documento postventa.
        DB::statement("
            CREATE UNIQUE INDEX mxc_documento_postventa_unico
            ON movimientos_cxc (documento_postventa_id)
            WHERE tipo IN ('REDUCCION_POSTVENTA', 'CANCELACION')
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION mxc_vinculo_documento_postventa_fn() RETURNS trigger AS \$\$
            DECLARE
                doc RECORD;
                cxc  RECORD;
            BEGIN
                IF NEW.tipo IN ('REDUCCION_POSTVENTA', 'CANCELACION') THEN
                IF NEW.documento_postventa_id IS NULL THEN
                    RAISE EXCEPTION 'La extinción de deuda por postventa exige su documento postventa.';
                END IF;
            ELSE
                IF NEW.documento_postventa_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Un documento postventa solo puede anclarse a REDUCCION_POSTVENTA o CANCELACION.';
                END IF;

                RETURN NEW;
            END IF;

            SELECT venta_id, tipo, total
                INTO doc
                FROM documentos_postventa
                WHERE id = NEW.documento_postventa_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'El documento postventa vinculado no existe.';
                END IF;

                SELECT venta_id
                INTO cxc
                FROM cuentas_por_cobrar
                WHERE id = NEW.cuenta_por_cobrar_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'La cuenta por cobrar del movimiento no existe.';
                END IF;

                IF cxc.venta_id IS DISTINCT FROM doc.venta_id THEN
                    RAISE EXCEPTION 'El movimiento de deuda debe pertenecer a la misma venta que su documento postventa.';
                END IF;

                IF (NEW.tipo = 'REDUCCION_POSTVENTA' AND doc.tipo <> 'DEVOLUCION')
                    OR (NEW.tipo = 'CANCELACION' AND doc.tipo <> 'CANCELACION') THEN
                    RAISE EXCEPTION 'El tipo del movimiento no corresponde al tipo del documento postventa.';
                END IF;

                IF NEW.monto_centavos > (doc.total * 100)::bigint THEN
                    RAISE EXCEPTION 'El movimiento de deuda supera el importe del documento postventa.';
                END IF;

                RETURN NEW;
            END; \$\$ LANGUAGE plpgsql
        ");

        DB::statement('
            DROP TRIGGER IF EXISTS mxc_vinculo_documento_postventa ON movimientos_cxc
        ');

        DB::statement('
            CREATE TRIGGER mxc_vinculo_documento_postventa
            BEFORE INSERT OR UPDATE OF
                documento_postventa_id, tipo, monto_centavos, cuenta_por_cobrar_id
            ON movimientos_cxc
            FOR EACH ROW EXECUTE FUNCTION mxc_vinculo_documento_postventa_fn()
        ');

        // Barrera BD de la reversa de un ABONO ya consumido por postventa.
        DB::statement("
            CREATE OR REPLACE FUNCTION mxc_reversa_bloquea_reembolso_fn() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.tipo = 'REVERSA_ABONO' THEN
                    IF EXISTS (
                        SELECT 1
                        FROM reembolsos_postventa
                        WHERE movimiento_cxc_id = NEW.movimiento_origen_id
                    ) THEN
                        RAISE EXCEPTION 'El abono ya fue utilizado en una operación postventa y no puede reversarse.';
                    END IF;
                END IF;

                RETURN NEW;
            END; \$\$ LANGUAGE plpgsql
        ");

        DB::statement('
            DROP TRIGGER IF EXISTS mxc_reversa_bloquea_reembolso ON movimientos_cxc
        ');

        DB::statement('
            CREATE TRIGGER mxc_reversa_bloquea_reembolso
            BEFORE INSERT ON movimientos_cxc
            FOR EACH ROW EXECUTE FUNCTION mxc_reversa_bloquea_reembolso_fn()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS mxc_reversa_bloquea_reembolso ON movimientos_cxc');
        DB::statement('DROP FUNCTION IF EXISTS mxc_reversa_bloquea_reembolso_fn');
        DB::statement('DROP TRIGGER IF EXISTS mxc_vinculo_documento_postventa ON movimientos_cxc');
        DB::statement('DROP FUNCTION IF EXISTS mxc_vinculo_documento_postventa_fn');

        DB::statement('DROP INDEX IF EXISTS mxc_documento_postventa_unico');
        DB::statement('ALTER TABLE movimientos_cxc DROP CONSTRAINT IF EXISTS mxc_documento_postventa_tipo_check');

        Schema::table('movimientos_cxc', function ($table) {
            $table->dropForeign(['documento_postventa_id']);
            $table->dropColumn('documento_postventa_id');
        });
    }
};
