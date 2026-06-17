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
        if (! Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('fillings', 'deleted_at')) {
            Schema::table('fillings', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
        if (! Schema::hasColumn('extras', 'deleted_at')) {
            Schema::table('extras', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('fillings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('extras', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
