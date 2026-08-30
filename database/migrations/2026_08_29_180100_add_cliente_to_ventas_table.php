<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            // Cliente opcional a nivel BD por compatibilidad histórica
            // (las ventas previas a B12 pueden no tener cliente). El checkout
            // nuevo exige cliente; el FK RESTRICT impide borrar un cliente
            // que tenga ventas (las ventas históricas jamás desaparecen).
            $table->foreignId('cliente_id')
                ->nullable()
                ->after('user_id')
                ->constrained('clientes')
                ->restrictOnDelete();

            // Snapshot histórico del cliente AL MOMENTO de la venta.
            // Se copia server-side en el checkout y NO se recalcula al editar
            // el Cliente posteriormente.
            $table->string('cliente_codigo', 20)->nullable()->after('cliente_id');
            $table->string('cliente_nombre')->nullable()->after('cliente_codigo');
            $table->string('cliente_rfc', 20)->nullable()->after('cliente_nombre');
            $table->string('cliente_telefono', 30)->nullable()->after('cliente_rfc');
            $table->string('cliente_email')->nullable()->after('cliente_telefono');
            $table->string('cliente_tipo', 20)->nullable()->after('cliente_email');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');

            $table->dropColumn([
                'cliente_codigo',
                'cliente_nombre',
                'cliente_rfc',
                'cliente_telefono',
                'cliente_email',
                'cliente_tipo',
            ]);
        });
    }
};
