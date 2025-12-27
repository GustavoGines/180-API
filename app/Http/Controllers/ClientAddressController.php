use App\Http\Resources\ClientAddressResource; // Added import

// ...

    public function store(StoreClientAddressRequest $request, Client $client)
    {
        $address = $client->addresses()->create($request->validated());
        return new ClientAddressResource($address);
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
