<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('categoria_id')
                ->nullable()
                ->after('categoria')          // lo dejamos junto a tu campo texto mientras migramos
                ->constrained('categorias');

            $table->index('categoria_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_id');
        });
    }

};
