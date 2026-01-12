<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {

            if (!Schema::hasColumn('movimientos', 'item_id')) {
                $table->foreignId('item_id')->nullable()->index();
            }

            if (!Schema::hasColumn('movimientos', 'user_id')) {
                $table->foreignId('user_id')->nullable()->index();
            }

            if (!Schema::hasColumn('movimientos', 'tipo')) {
                $table->string('tipo', 40)->nullable()->index();
            }

            if (!Schema::hasColumn('movimientos', 'de_estado')) {
                $table->string('de_estado', 30)->nullable();
            }

            if (!Schema::hasColumn('movimientos', 'a_estado')) {
                $table->string('a_estado', 30)->nullable();
            }

            if (!Schema::hasColumn('movimientos', 'de_ubicacion_id')) {
                $table->foreignId('de_ubicacion_id')->nullable()->index();
            }

            if (!Schema::hasColumn('movimientos', 'a_ubicacion_id')) {
                $table->foreignId('a_ubicacion_id')->nullable()->index();
            }

            if (!Schema::hasColumn('movimientos', 'notas')) {
                $table->text('notas')->nullable();
            }

            if (!Schema::hasColumn('movimientos', 'evidencia_path')) {
                $table->string('evidencia_path')->nullable();
            }

            if (!Schema::hasColumn('movimientos', 'fecha')) {
                $table->timestamp('fecha')->nullable()->index();
            }
        });

        // Foreign keys (fuera del closure para que no truene por orden)
        Schema::table('movimientos', function (Blueprint $table) {

            // item_id -> items.id
            try {
                $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            } catch (\Throwable $e) {}

            // user_id -> users.id
            try {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            } catch (\Throwable $e) {}

            // de_ubicacion_id -> ubicaciones.id
            try {
                $table->foreign('de_ubicacion_id')->references('id')->on('ubicaciones')->nullOnDelete();
            } catch (\Throwable $e) {}

            // a_ubicacion_id -> ubicaciones.id
            try {
                $table->foreign('a_ubicacion_id')->references('id')->on('ubicaciones')->nullOnDelete();
            } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        // En dev casi nunca haces rollback de esto; si quieres lo armamos luego.
    }
};
