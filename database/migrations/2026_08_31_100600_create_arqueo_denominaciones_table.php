<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desglose de denominaciones del arqueo (MXN). El efectivo_contado se
     * RECALCULA server-side como SUM(denominacion * cantidad).
     */
    public function up(): void
    {
        Schema::create('arqueo_denominaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arqueo_id')->constrained('arqueos_caja')->restrictOnDelete();
            $table->decimal('denominacion', 10, 2);
            $table->unsignedInteger('cantidad')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('arqueo_id');
        });

        DB::statement('
            ALTER TABLE arqueo_denominaciones
            ADD CONSTRAINT arqueo_denominaciones_denominacion_check
            CHECK (denominacion > 0)
        ');

        DB::statement('
            ALTER TABLE arqueo_denominaciones
            ADD CONSTRAINT arqueo_denominaciones_subtotal_check
            CHECK (subtotal >= 0)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('arqueo_denominaciones');
    }
};
