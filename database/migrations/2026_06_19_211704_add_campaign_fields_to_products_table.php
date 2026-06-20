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
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_combo')->default(false)->after('multiplier_adjustment_per_kg');
            $table->string('campaign_name')->nullable()->after('is_combo');
            $table->date('available_from')->nullable()->after('campaign_name');
            $table->date('available_until')->nullable()->after('available_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_combo',
                'campaign_name',
                'available_from',
                'available_until'
            ]);
        });
    }
};
