<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests en memoria) no soporta drop/recrear FKs por nombre.
        // Esta migración es exclusiva para PostgreSQL.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Limpiar huérfanos antes de agregar la FK con onDelete
        DB::statement('
            UPDATE orders
            SET client_address_id = NULL
            WHERE client_address_id IS NOT NULL
              AND client_address_id NOT IN (SELECT id FROM client_addresses)
        ');

        Schema::table('orders', function (Blueprint $table) {
            // Eliminar el FK existente (sin onDelete definido)
            $table->dropForeign('orders_client_address_id_foreign');

            // Re-agregar con nullOnDelete: si se borra una dirección,
            // el pedido queda sin dirección pero no se borra.
            $table->foreign('client_address_id')
                ->references('id')
                ->on('client_addresses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_client_address_id_foreign');

            $table->foreign('client_address_id')
                ->references('id')
                ->on('client_addresses');
        });
    }
};
