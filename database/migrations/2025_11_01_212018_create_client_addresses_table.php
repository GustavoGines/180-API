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
        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            
            // Relacion con el cliente
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->onDelete('cascade'); // Si se borra el cliente, se borran sus direcciones

            // El "apodo" de la dirección (Casa, Oficina, etc.)
            $table->string('label', 100)->default('Principal'); 
            
            // Campo para la dirección de texto
            $table->string('address_line_1', 255)->nullable();
            
            // Campos para coordenadas GPS
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Campo para la URL de Google Maps
            $table->text('google_maps_url')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Papelera de reciclaje
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
