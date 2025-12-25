<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_paid')->default(false)->after('status');
        });

        // Backfill: Marcar como pagado si el depósito es mayor o igual al total (y total > 0)
        // PostgreSQL: Usar 'true' para booleanos
        DB::statement("UPDATE orders SET is_paid = true WHERE deposit >= total AND total > 0");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_paid');
        });
    }
};
