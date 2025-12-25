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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category'); // torta, mesaDulce, box
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2)->default(0);
            $table->string('unit_type'); // kg, unit, dozen...
            $table->boolean('allow_half_dozen')->default(false);
            $table->decimal('half_dozen_price', 10, 2)->nullable();
            // Multiplier adjustment for cakes if needed
            $table->decimal('multiplier_adjustment_per_kg', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('variant_name'); // e.g., 'size20cm', 'size24cm'
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });

        Schema::create('fillings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price_per_kg', 10, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('price_type')->default('per_unit'); // per_unit, per_kg
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras');
        Schema::dropIfExists('fillings');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
