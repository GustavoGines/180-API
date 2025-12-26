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
            // Eliminar el trigger conflictivo que referencia una columna inexistente 'unit_price'
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_after_change ON order_items');
            DB::unprepared('DROP FUNCTION IF EXISTS update_order_total(BIGINT)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No restauramos el trigger porque estaba roto (buscaba 'unit_price' que no existe)
    }
};
