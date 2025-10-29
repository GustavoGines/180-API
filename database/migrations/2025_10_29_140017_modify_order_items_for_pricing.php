<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // Asegúrate de que esta línea esté al inicio

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // 1. Añadir nuevas columnas
            $table->decimal('base_price', 10, 2)->after('unit_price')->default(0.00);
            $table->decimal('adjustments', 10, 2)->after('base_price')->default(0.00);
            $table->text('customization_notes')->after('adjustments')->nullable();
        });

        // 2. Migración de datos (Transferir el precio final antiguo a base_price)
        DB::table('order_items')->whereNotNull('unit_price')->update([
            'base_price' => DB::raw('unit_price'), // El precio que existía se convierte en el precio base
            'adjustments' => 0.00                 // Los ajustes inician en cero
        ]);

        Schema::table('order_items', function (Blueprint $table) {
            // 3. Eliminar la columna antigua 'unit_price'
            // Ya no la necesitamos, y esto simplifica la base de datos.
            $table->dropColumn('unit_price'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // En caso de rollback, restaurar la columna 'unit_price' como si fuera 'base_price'
        Schema::table('order_items', function (Blueprint $table) {
            // 1. Restaurar 'unit_price' (con los datos que estaban en 'base_price')
            $table->decimal('unit_price', 10, 2)->after('id')->nullable(false)->default(0.00); 
            
            // 2. Intentar mover los datos de base_price a unit_price (solo si no es un fresh migration)
            if (Schema::hasColumn('order_items', 'base_price')) {
                 DB::table('order_items')->update([
                    'unit_price' => DB::raw('base_price')
                 ]);
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            // 3. Eliminar las nuevas columnas
            $table->dropColumn('base_price');
            $table->dropColumn('adjustments');
            $table->dropColumn('customization_notes');
        });
    }
};