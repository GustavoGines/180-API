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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('event_date')->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('status', 20)->default('confirmed')->index(); // draft|confirmed|delivered|canceled
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('deposit', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('google_event_id', 128)->nullable()->unique();
            $table->timestamps();

            $table->index(['event_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
