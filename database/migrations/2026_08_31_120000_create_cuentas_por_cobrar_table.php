<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B15.2 — Núcleo CxC.
     *
     * cuentas_por_cobrar: deuda económica del cliente.
     *
     * - folio: sequence cxc_folio_seq_generator -> CXC-000001 (creates siguen el
     *   patrón Venta/Cliente/Item; NUNCA MAX()+1 en runtime).
     * - venta_id UNIQUE: máxima una CxC por venta.
     * - FK compuesta (venta_id, cliente_id) -> ventas(id, cliente_id):
     *   garantía declarativa de que la CxC apunta a una venta cuyo cliente
     *   coincide con el de la deuda, y además protege cambios posteriores de
     *   venta.cliente_id.
     * - Inmutabilidad de campos históricos y prohibición de DELETE: triggers
     *   PostgreSQL (marco de dominio, ver funciones abajo).
     */
    public function up(): void
    {
        // PostgreSQL exige que el par de columnas referenciado tenga una UNIQUE.
        // Deliberadamente redundante respecto al PK (id), pero requerido para
        // poder referenciar exactamente (id, cliente_id).
        DB::statement('
            ALTER TABLE ventas
            ADD CONSTRAINT ventas_id_cliente_unique UNIQUE (id, cliente_id)
        ');

        // Sequence DETERMINISTA. La tabla cuentas_por_cobrar nace vacía en esta
        // migración, así que se descarta cualquier sequence huérfana previa y se
        // garantiza que una reconstrucción completa del esquema arranque en
        // CXC-000001. NO es MAX()+1 ni setval: la tabla acaba de nacer vacía.
        // Los huecos normales por rollback en operación son válidos y NO se
        // reutilizan (la sequence solo avanza).
        DB::statement('DROP SEQUENCE IF EXISTS cxc_folio_seq_generator');
        DB::statement('CREATE SEQUENCE cxc_folio_seq_generator START WITH 1 INCREMENT BY 1');

        Schema::create('cuentas_por_cobrar', function (Blueprint $table) {
            $table->id();

            $table->string('folio', 20)->unique(); // CXC-000001 (sequence)

            $table->bigInteger('venta_id');
            $table->bigInteger('cliente_id');

            $table->bigInteger('importe_original_centavos');
            $table->bigInteger('saldo_centavos');

            $table->integer('dias_credito_aplicados');
            $table->date('fecha_vencimiento');

            $table->string('estado', 20);

            $table->timestamps();

            $table->unique('venta_id'); // máxima una CxC por venta

            // Cliente no puede desaparecer mientras exista deuda.
            $table->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->restrictOnDelete();

            $table->index('estado');
            $table->index('fecha_vencimiento');
        });

        // Índice PARCIAL real de PostgreSQL para la consulta de exposición
        // (cliente con saldo vivo). Se crea con SQL explícito porque el
        // PostgresGrammar de Laravel 12 no compila el WHERE de un IndexDefinition.
        DB::statement('
            CREATE INDEX cxc_cliente_saldo_activo_idx
            ON cuentas_por_cobrar (cliente_id)
            WHERE saldo_centavos > 0
        ');

        // FK compuesta: venta/cliente consistentes (garantía declarativa).
        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_venta_cliente_fk
            FOREIGN KEY (venta_id, cliente_id)
            REFERENCES ventas (id, cliente_id)
            ON DELETE RESTRICT
        ');

        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_importe_original_positive
            CHECK (importe_original_centavos > 0)
        ');

        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_saldo_non_negative
            CHECK (saldo_centavos >= 0)
        ');

        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_saldo_leq_original
            CHECK (saldo_centavos <= importe_original_centavos)
        ');

        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_dias_credito_positive
            CHECK (dias_credito_aplicados > 0)
        ');

        DB::statement("
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_estado_valido
            CHECK (estado IN ('PENDIENTE', 'PARCIAL', 'SALDADA', 'CANCELADA'))
        ");

        // Estado y saldo siempre consistentes.
        DB::statement('
            ALTER TABLE cuentas_por_cobrar
            ADD CONSTRAINT cxc_estado_saldo
            CHECK (
                (estado = \'PENDIENTE\' AND saldo_centavos = importe_original_centavos)
                OR (estado = \'PARCIAL\' AND saldo_centavos > 0 AND saldo_centavos < importe_original_centavos)
                OR (estado IN (\'SALDADA\', \'CANCELADA\') AND saldo_centavos = 0)
            )
        ');

        // Función que protege la historia: campos económicos/caracterizadores de
        // la deuda son inmutables tras el INSERT salvo saldo/estado/updated_at.
        // created_at es histórico (instantánea de nacimiento); updated_at no.
        DB::statement('
            CREATE OR REPLACE FUNCTION cxc_proteger_historial_fn() RETURNS trigger AS $$
            BEGIN
                IF NEW.folio IS DISTINCT FROM OLD.folio
                OR NEW.venta_id IS DISTINCT FROM OLD.venta_id
                OR NEW.cliente_id IS DISTINCT FROM OLD.cliente_id
                OR NEW.importe_original_centavos IS DISTINCT FROM OLD.importe_original_centavos
                OR NEW.dias_credito_aplicados IS DISTINCT FROM OLD.dias_credito_aplicados
                OR NEW.fecha_vencimiento IS DISTINCT FROM OLD.fecha_vencimiento
                OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION \'Los campos históricos de la cuenta por cobrar son inmutables.\';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
        ');

        DB::statement('
            DROP TRIGGER IF EXISTS cxc_proteger_historial ON cuentas_por_cobrar
        ');

        DB::statement('
            CREATE TRIGGER cxc_proteger_historial
            BEFORE UPDATE ON cuentas_por_cobrar
            FOR EACH ROW EXECUTE FUNCTION cxc_proteger_historial_fn()
        ');

        // Una cuenta por cobrar jamás se elimina operacionalmente: el ledger
        // conserva el historial económico inmutable; saldo_centavos es el
        // saldo operacional materializado.
        DB::statement('
            CREATE OR REPLACE FUNCTION cxc_proteger_delete_fn() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION \'Las cuentas por cobrar no pueden eliminarse.\';
            END; $$ LANGUAGE plpgsql
        ');

        DB::statement('
            DROP TRIGGER IF EXISTS cxc_proteger_delete ON cuentas_por_cobrar
        ');

        DB::statement('
            CREATE TRIGGER cxc_proteger_delete
            BEFORE DELETE ON cuentas_por_cobrar
            FOR EACH ROW EXECUTE FUNCTION cxc_proteger_delete_fn()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cxc_proteger_delete ON cuentas_por_cobrar');
        DB::statement('DROP FUNCTION IF EXISTS cxc_proteger_delete_fn');
        DB::statement('DROP TRIGGER IF EXISTS cxc_proteger_historial ON cuentas_por_cobrar');
        DB::statement('DROP FUNCTION IF EXISTS cxc_proteger_historial_fn');

        Schema::dropIfExists('cuentas_por_cobrar');

        DB::statement('DROP SEQUENCE IF EXISTS cxc_folio_seq_generator');

        DB::statement('ALTER TABLE ventas DROP CONSTRAINT IF EXISTS ventas_id_cliente_unique');
    }
};
