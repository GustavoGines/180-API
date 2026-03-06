<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Http\Requests\StoreClientAddressRequest;
use App\Http\Resources\ClientAddressResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ClientAddressController extends Controller
{
    public function store(StoreClientAddressRequest $request, Client $client)
    {
        Log::info('ClientAddressController::store called', [
            'client_id' => $client->id,
            'request_data' => $request->all(),
        ]);

        try {
            $data = $request->validated();
            Log::info('Validated data:', $data);
            
            $address = $client->addresses()->create($data);
            Log::info('Address created successfully:', ['id' => $address->id]);

            return new ClientAddressResource($address);
        } catch (\Exception $e) {
            Log::error('Error creating address:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Error backend creando address: ' . $e->getMessage()], 500);
        }
    }

    public function update(StoreClientAddressRequest $request, Client $client, ClientAddress $address)
    {
        if ($address->client_id !== $client->id) {
            return response()->json(['message' => 'Conflicto: La dirección no pertenece a este cliente.'], Response::HTTP_CONFLICT);
        }

        $address->update($request->validated());
        return new ClientAddressResource($address->fresh());
    }

    /**
     * DELETE /api/clients/{client}/addresses/{address}
     * Mueve una dirección a la papelera (Soft Delete).
     */
    public function destroy(Client $client, ClientAddress $address)
    {
        if ($address->client_id !== $client->id) {
            return response()->json(['message' => 'Conflicto: La dirección no pertenece a este cliente.'], Response::HTTP_CONFLICT);
        }

        // Validación: No permitir borrar si está en uso por un pedido
        if ($address->orders()->exists()) {
             return response()->json(['message' => 'Conflicto: Esta dirección está siendo usada en uno o más pedidos y no puede ser borrada.'], Response::HTTP_CONFLICT);
        }

        $address->delete();
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
