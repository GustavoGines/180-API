use App\Http\Resources\ClientResource; // Added import

// ...

    public function index(Request $request)
    {
        // ... (lines 18-52 remain same)
        $searchQuery = $request->query('query');

        $clients = Client::query()
             // ... existing query logic ...
            ->when($searchQuery, function ($builder) use ($searchQuery) {
                $searchTerms = explode(' ', $searchQuery);
                return $builder->where(function ($subQuery) use ($searchTerms) {
                    foreach ($searchTerms as $term) {
                        $term = trim($term);
                        if (! empty($term)) {
                            $likeTerm = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                            $subQuery->where(function ($wordQuery) use ($likeTerm) {
                                $wordQuery->whereRaw('unaccent(name) ILIKE unaccent(?)', [$likeTerm])
                                    ->orWhereRaw('unaccent(phone) ILIKE unaccent(?)', [$likeTerm])
                                    ->orWhereRaw('unaccent(email) ILIKE unaccent(?)', [$likeTerm]);
                            });
                        }
                    }
                });
            })
            ->orderBy('name')
            ->paginate(500);

        return ClientResource::collection($clients);
    }

    public function store(StoreClientRequest $request)
    {
        $validated = $request->validated();
        $name = trim($validated['name']);
        if (isset($validated['phone'])) {
            $validated['phone'] = $this->normalizePhone($validated['phone']);
        }
        $validated['name'] = $name;

        $existingClient = Client::withTrashed()
            ->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])
            ->first();

        if ($existingClient) {
            // ... existing conflict logic ...
            if ($existingClient->trashed()) {
                return response()->json([
                    'message' => 'Un cliente con este nombre ya existe en la papelera.',
                    'client' => new ClientResource($existingClient),
                ], Response::HTTP_CONFLICT);
            } else {
                 return response()->json([
                    'message' => 'Un cliente con este nombre ya existe.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $client = Client::create($validated);

        return new ClientResource($client);
    }

    public function show(Client $client)
    {
        $client->load('addresses');
        return new ClientResource($client);
    }

    public function update(StoreClientRequest $request, Client $client)
    {
        $validated = $request->validated();
        $name = trim($validated['name']);
        if (isset($validated['phone'])) {
            $validated['phone'] = $this->normalizePhone($validated['phone']);
        }
        $validated['name'] = $name;

        $existingClient = Client::withTrashed()
            ->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])
            ->where('id', '!=', $client->id)
            ->first();

        if ($existingClient) {
            if ($existingClient->trashed()) {
                return response()->json([
                    'message' => 'Otro cliente con este nombre ya existe en la papelera.',
                    'client' => new ClientResource($existingClient),
                ], Response::HTTP_CONFLICT);
            } else {
                return response()->json([
                    'message' => 'Otro cliente con este nombre ya existe.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $client->update($validated);

        return new ClientResource($client->fresh());
    }
    
    // ... destroy remains mostly same ...

    public function trashed()
    {
        $trashedClients = Client::onlyTrashed()->orderBy('deleted_at', 'desc')->get();
        return ClientResource::collection($trashedClients);
    }

    public function restore($id)
    {
        $client = Client::withTrashed()->find($id);

        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND);
        }

        if (! $client->trashed()) {
            return response()->json(['message' => 'El cliente no está eliminado'], Response::HTTP_BAD_REQUEST);
        }

        $client->restore();

        return new ClientResource($client);
    }

    /**
     * DELETE /api/clients/{id}/force-delete
     * Elimina permanentemente un cliente de la base de datos.
     */
    public function forceDelete($id)
    {
        // 1. Buscamos al cliente INCLUYENDO los de la papelera
        $client = Client::withTrashed()->find($id);

        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND);
        }

        // 2. ¡MUY IMPORTANTE! Revalidar que no tenga pedidos.
        // NO DEBERÍAS borrar permanentemente un cliente con historial de ventas.
        if ($client->orders()->exists()) {
            return response()->json([
                'message' => '¡Conflicto! Este cliente tiene pedidos asociados y no puede ser eliminado permanentemente.',
            ], Response::HTTP_CONFLICT); // 409
        }

        // 3. Si no tiene pedidos, ahora sí, borrado físico.
        $client->forceDelete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        // 1. Eliminar todo lo que no sea número, manteniendo el signo '+'
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        // Si el número tiene menos de 10 dígitos útiles, es demasiado corto para normalizar
        if (strlen(ltrim($normalized, '+')) < 10) {
            return $normalized;
        }

        // 2. Si ya empieza con +549, está casi perfecto
        if (str_starts_with($normalized, '+549')) {
            return $normalized;
        }

        // 3. Si empieza con 549, le añadimos el '+'
        if (str_starts_with($normalized, '549')) {
            return '+'.$normalized;
        }

        // 4. Si empieza con 0 o 15, intentamos eliminar prefijos locales de llamadas.
        // Esto es crucial para los números sacados de la agenda (que a menudo tienen 15 o 0).
        if (str_starts_with($normalized, '0') || str_starts_with($normalized, '15')) {
            $normalized = ltrim($normalized, '0');
            $normalized = ltrim($normalized, '15');
        }

        // 5. Si ahora tiene la longitud típica de un número móvil argentino (ej: 10 dígitos o similar),
        // y NO tiene código de país, le agregamos el código +549.
        // Nota: Esto es una simplificación y es mejor que el número siempre esté en el formato +549 en la BD.
        if (strlen($normalized) >= 10 && strlen($normalized) <= 12 && ! str_starts_with($normalized, '54')) {
            return '+549'.$normalized;
        }

        // Fallback: Devolvemos el número tal cual, limpio de caracteres
        return $normalized;
    }
}
