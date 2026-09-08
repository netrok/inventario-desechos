<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.2 — Ledger económico de la deuda.
     *
     * movimientos_cxc: registro INMUTABLE (append-only) de cada cambio de saldo
     * de una cuenta por cobrar. No lleva updated_at; created_at lo genera la BD.
     *
     * La aritmética ledger está codificada en el CHECK cxc_aritmetica_ledger:
     * un movimiento cuyo monto no explique el cambio de saldo es imposible.
     */
    public function up(): void
    {
        Schema::create('movimientos_cxc', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('cuenta_por_cobrar_id');
            $table->bigInteger('user_id');

            $table->string('tipo', 30);

            $table->bigInteger('monto_centavos');
            $table->bigInteger('saldo_antes_centavos');
            $table->bigInteger('saldo_despues_centavos');

            $table->string('metodo', 20)->nullable();
            $table->string('referencia', 100)->nullable();

            $table->bigInteger('movimiento_origen_id')->nullable();

            $table->text('observaciones')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->foreign('cuenta_por_cobrar_id')
                ->references('id')
                ->on('cuentas_por_cobrar')
                ->restrictOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('movimiento_origen_id')
                ->references('id')
                ->on('movimientos_cxc')
                ->restrictOnDelete();

            $table->index(['cuenta_por_cobrar_id', 'id']);
            $table->index('user_id');
            $table->index('tipo');
            $table->index('movimiento_origen_id');
        });

        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_monto_positive
            CHECK (monto_centavos > 0)
        ');

        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_saldo_antes_non_negative
            CHECK (saldo_antes_centavos >= 0)
        ');

        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_saldo_despues_non_negative
            CHECK (saldo_despues_centavos >= 0)
        ');

        DB::statement("
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_tipo_valido
            CHECK (tipo IN (
                'CARGO_INICIAL',
                'ABONO',
                'REVERSA_ABONO',
                'REDUCCION_POSTVENTA',
                'CANCELACION'
            ))
        ");

        DB::statement("
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_metodo_valido
            CHECK (metodo IS NULL OR metodo IN ('EFECTIVO', 'TARJETA', 'TRANSFERENCIA'))
        ");

        // ABONO registra el método monetario real; el resto (incluida la
        // REVERSA_ABONO) no lleva método: se deriva del ABONO original.
        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_abono_requiere_metodo
            CHECK (tipo <> \'ABONO\' OR metodo IS NOT NULL)
        ');

        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_no_abono_metodo_nulo
            CHECK (tipo = \'ABONO\' OR metodo IS NULL)
        ');

        // REVERSA_ABONO siempre ancla a un ABONO original; el resto jamás.
        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_reversa_requiere_origen
            CHECK (tipo <> \'REVERSA_ABONO\' OR movimiento_origen_id IS NOT NULL)
        ');

        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_no_reversa_sin_origen
            CHECK (tipo = \'REVERSA_ABONO\' OR movimiento_origen_id IS NULL)
        ');

        // Aritmética del ledger: el monto explica EXACTAMENTE el cambio de saldo.
        DB::statement('
            ALTER TABLE movimientos_cxc
            ADD CONSTRAINT mxc_aritmetica_ledger
            CHECK (
                (tipo = \'CARGO_INICIAL\'
                    AND saldo_antes_centavos = 0
                    AND saldo_despues_centavos = monto_centavos)
                OR
                (tipo = \'REVERSA_ABONO\'
                    AND saldo_despues_centavos = saldo_antes_centavos + monto_centavos)
                OR
                (tipo = \'ABONO\'
                    AND saldo_despues_centavos = saldo_antes_centavos - monto_centavos)
                OR
                (tipo = \'REDUCCION_POSTVENTA\'
                    AND saldo_despues_centavos = saldo_antes_centavos - monto_centavos)
                OR
                (tipo = \'CANCELACION\'
                    AND saldo_despues_centavos = 0
                    AND saldo_antes_centavos = monto_centavos)
            )
        ');

        // Máximo un CARGO_INICIAL por cuenta a nivel BD.
        DB::statement('
            CREATE UNIQUE INDEX mxc_cargo_inicial_unico
            ON movimientos_cxc (cuenta_por_cobrar_id)
            WHERE tipo = \'CARGO_INICIAL\'
        ');

        // Máximo una REVERSA_ABONO por ABONO a nivel BD (REVERSA total).
        DB::statement('
            CREATE UNIQUE INDEX mxc_reversa_unica
            ON movimientos_cxc (movimiento_origen_id)
            WHERE tipo = \'REVERSA_ABONO\'
        ');

        // Append-only: ninguna fila existente del ledger puede modificarse o
        // eliminarse. Correcciones futuras = nuevo movimiento compensatorio.
        DB::statement('
            CREATE OR REPLACE FUNCTION mxc_append_only_fn() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION \'Los movimientos CxC son de solo lectura (append-only).\';
            END; $$ LANGUAGE plpgsql
        ');

        DB::statement('
            DROP TRIGGER IF EXISTS mxc_append_only ON movimientos_cxc
        ');

        DB::statement('
            CREATE TRIGGER mxc_append_only
            BEFORE UPDATE OR DELETE ON movimientos_cxc
            FOR EACH ROW EXECUTE FUNCTION mxc_append_only_fn()
        ');

        // Valida REVERSA_ABONO contra su ABONO original.
        DB::statement('
            CREATE OR REPLACE FUNCTION mxc_reversa_valida_origen_fn() RETURNS trigger AS $$
            DECLARE
                orig RECORD;
            BEGIN
                IF NEW.tipo = \'REVERSA_ABONO\' THEN
                    SELECT monto_centavos, cuenta_por_cobrar_id, tipo
                    INTO orig
                    FROM movimientos_cxc
                    WHERE id = NEW.movimiento_origen_id;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION \'La reversa requiere un ABONO original existente.\';
                    END IF;

                    IF orig.tipo <> \'ABONO\' THEN
                        RAISE EXCEPTION \'La reversa solo puede anclarse a un movimiento tipo ABONO.\';
                    END IF;

                    IF orig.cuenta_por_cobrar_id IS DISTINCT FROM NEW.cuenta_por_cobrar_id THEN
                        RAISE EXCEPTION \'La reversa debe pertenecer a la misma cuenta que su ABONO original.\';
                    END IF;

                    IF orig.monto_centavos IS DISTINCT FROM NEW.monto_centavos THEN
                        RAISE EXCEPTION \'La reversa debe coincidir exactamente con el monto del ABONO original.\';
                    END IF;
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql
        ');

        DB::statement('
            DROP TRIGGER IF EXISTS mxc_reversa_valida_origen ON movimientos_cxc
        ');

        DB::statement('
            CREATE TRIGGER mxc_reversa_valida_origen
            BEFORE INSERT ON movimientos_cxc
            FOR EACH ROW EXECUTE FUNCTION mxc_reversa_valida_origen_fn()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS mxc_reversa_valida_origen ON movimientos_cxc');
        DB::statement('DROP FUNCTION IF EXISTS mxc_reversa_valida_origen_fn');
        DB::statement('DROP TRIGGER IF EXISTS mxc_append_only ON movimientos_cxc');
        DB::statement('DROP FUNCTION IF EXISTS mxc_append_only_fn');

        Schema::dropIfExists('movimientos_cxc');
    }
};
