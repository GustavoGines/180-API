<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Nombre REAL del trigger detectado en 2025_10_12_200310...
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_after_insupddel ON order_items');

            // También borramos las funciones asociadas para limpiar todo
            DB::unprepared('DROP FUNCTION IF EXISTS trg_order_items_after_change()');
            DB::unprepared('DROP FUNCTION IF EXISTS update_order_total(BIGINT)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No restauramos nada, cleanup definitivo.
    }
};
