<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * GET /api/clients?query=ana
     * Lista clientes con búsqueda por nombre/teléfono/email (ILIKE para PostgreSQL).
     */
    public function index(Request $request)
    {
        $searchQuery = $request->query('query');

        $clients = Client::query()
            ->when($searchQuery, function ($builder) use ($searchQuery) {
                // Para PostgreSQL usamos ILIKE (case-insensitive)
                $like = '%' . str_replace('%', '\%', $searchQuery) . '%';
                $builder->where(function ($subquery) use ($like) {
                    $subquery->whereRaw('name ILIKE ?', [$like])
                        ->orWhereRaw('phone ILIKE ?', [$like])
                        ->orWhereRaw('email ILIKE ?', [$like]);
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return response()->json($clients);
    }

    /**
     * POST /api/clients
     * Crea un cliente.
     */
    public function store(StoreClientRequest $request)
    {
        $validated = $request->validated();

        $client = Client::create($validated);

        return response()->json($client, Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

     /**
     * PUT /api/clients/{client}
     * Actualiza un cliente.
     */
    public function update(StoreClientRequest $request, Client $client)
    {
        $validated = $request->validated();

        $client->update($validated);

        return response()->json($client->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
