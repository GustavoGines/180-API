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
        // PostgreSQL: Usar 'true' para booleanos
        DB::statement("UPDATE orders SET is_paid = true WHERE status = 'delivered'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No acción.
    }
};
