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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No se remueve unaccent en down para no afectar otras funciones de la BD.
    }
};
