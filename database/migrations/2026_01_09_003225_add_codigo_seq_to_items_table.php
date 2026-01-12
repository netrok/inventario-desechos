<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('codigo_seq')->nullable()->after('id');
            $table->unique('codigo_seq', 'items_codigo_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_codigo_seq_unique');
            $table->dropColumn('codigo_seq');
        });
    }
};
