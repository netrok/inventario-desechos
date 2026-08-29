<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table) {
            $table->id();

            // Una sola fila singleton (id = 1). No hay CMS: sólo datos de ticket
            // y preferencias operativas. NO se guardan secretos aquí.

            // Datos mostrados en tickets / comprobantes
            $table->string('empresa_nombre')->nullable();
            $table->string('empresa_rfc', 20)->nullable();
            $table->string('empresa_telefono', 30)->nullable();
            $table->string('empresa_email')->nullable();
            $table->text('empresa_direccion')->nullable();
            $table->text('ticket_pie')->nullable();

            // Ancho de ticket térmico: SOLO 58 o 80 (reforzado a nivel DB).
            $table->smallInteger('ticket_ancho')->default(80);

            // Autoprint: abrir/imprimir el ticket automáticamente tras el
            // checkout (pensado para estaciones con --kiosk-printing).
            $table->boolean('ticket_autoprint')->default(false);

            $table->timestamps();
        });

        // Reforzar a nivel DB que el ancho de ticket sólo sea 58 o 80.
        DB::statement(
            'ALTER TABLE configuracion ADD CONSTRAINT configuracion_ticket_ancho_check CHECK (ticket_ancho IN (58, 80))'
        );

        // Garantía determinista de UNA sola fila de configuración, incluso bajo
        // concurrencia: un índice único sobre una constante sólo permite una
        // fila en toda la tabla (la clásica técnica de "singleton en BD").
        // Sin esta restricción, dos peticiones concurrentes podrían crear
        // filas id=1, id=2, id=3 vía `firstOrCreate`/`create`.
        DB::statement('CREATE UNIQUE INDEX configuracion_singleton ON configuracion ((true))');
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
