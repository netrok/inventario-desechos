<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_add_fecha_to_movimientos_table.php
    public function up(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->timestamp('fecha')->nullable()->after('evidencia_path');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropColumn('fecha');
        });
    }

};
