<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ubicaciones', 'activo')) {
            Schema::table('ubicaciones', function (Blueprint $table) {
                $table->boolean('activo')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ubicaciones', 'activo')) {
            Schema::table('ubicaciones', function (Blueprint $table) {
                $table->dropColumn('activo');
            });
        }
    }
};
