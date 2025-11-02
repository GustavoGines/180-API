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
        Schema::table('orders', function (Blueprint $table) {
            // Tu código. Se enlaza a la nueva tabla 'client_addresses'
            // y si se borra la dirección, el pedido queda 'null' (pero no se borra el pedido)
            $table->foreignId('client_address_id')
                  ->nullable()
                  ->after('client_id') // Ubicación opcional
                  ->constrained('client_addresses') // Tabla correcta
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 👇 CORRECCIÓN: Se debe borrar la 'foreign key' antes que la columna
            $table->dropForeign(['client_address_id']);
            $table->dropColumn('client_address_id');
        });
    }
};
