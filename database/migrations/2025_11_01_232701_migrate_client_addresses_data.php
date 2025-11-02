<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mueve las direcciones de la tabla 'clients' a 'client_addresses'.
     */
    public function up(): void
    {
        // Usamos DB::table por eficiencia y para evitar problemas de $fillable
        $oldClients = DB::table('clients')->whereNotNull('address')->where('address', '!=', '')->get();

        foreach ($oldClients as $client) {
            // Intentar detectar si es coordenada
            $isCoords = preg_match('/^-?[\d\.]+,\s*-?[\d\.]+$/', $client->address);
            
            $data = [
                'client_id' => $client->id,
                'label' => 'Principal', // Etiqueta por defecto
                'address_line_1' => null,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'created_at' => $client->created_at,
                'updated_at' => $client->updated_at,
            ];

            if ($isCoords) {
                // Es coordenada
                $coords = explode(',', $client->address);
                $data['latitude'] = trim($coords[0]);
                $data['longitude'] = trim($coords[1]);
                $data['address_line_1'] = 'Ubicación GPS'; // Texto genérico
            } else {
                // Es texto
                $data['address_line_1'] = $client->address;
            }

            DB::table('client_addresses')->insert($data);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Borra solo las direcciones que se migraron
         DB::table('client_addresses')->where('label', 'Principal')->delete();
    }
};
