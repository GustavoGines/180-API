<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Acelera la búsqueda en unregister: WHERE fcm_token = ?
            $table->index('fcm_token', 'devices_fcm_token_idx');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('devices_fcm_token_idx');
        });
    }
};
