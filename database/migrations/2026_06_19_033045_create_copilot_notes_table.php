<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('copilot_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->json('ui_widget')->nullable();
            $table->string('source_context', 150)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('copilot_notes');
    }
};
