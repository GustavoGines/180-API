<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Add new columns for detailed pricing
            // 'after' places the columns nicely in the table structure
            $table->decimal('base_price', 10, 2)->after('unit_price')->default(0.00); // Precio base (puede ser por kg, unidad, etc.)
            $table->decimal('adjustments', 10, 2)->after('base_price')->default(0.00); // Ajustes (+/-) sobre el precio base
            $table->text('customization_notes')->after('adjustments')->nullable(); // Notas que explican el ajuste

            // Make original unit_price nullable (optional but recommended for transition)
            // You might need to install doctrine/dbal: composer require doctrine/dbal
            $table->decimal('unit_price', 10, 2)->nullable()->change();
        });

        // --- Data Migration (Optional but Recommended) ---
        // If you want to populate the new fields based on existing unit_price
        // Assuming unit_price previously held the final price
         DB::table('order_items')->whereNotNull('unit_price')->update([
             'base_price' => DB::raw('unit_price'), // Set base_price to the old unit_price initially
             'adjustments' => 0.00                  // Assume zero adjustments for old data
         ]);
        // --- End Data Migration ---
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Drop the new columns if rolling back
            $table->dropColumn('base_price');
            $table->dropColumn('adjustments');
            $table->dropColumn('customization_notes');

            // Revert unit_price back to not nullable if needed
            // Be careful if you have existing null data after running 'up'
            $table->decimal('unit_price', 10, 2)->nullable(false)->change();
        });
    }
};