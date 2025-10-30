<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;


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
                $like = '%'.str_replace('%', '\%', $searchQuery).'%';
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
        $name = $validated['name'];

        // 1. Revisa si un cliente con ese nombre EXISTE, INCLUSO SI ESTÁ BORRADO
        $existingClient = Client::withTrashed() 
            ->where('name', $name)
            // ->orWhere('email', $validated['email']) // Opcional: chequear email también
            ->first();

        if ($existingClient) {
            // 2. Si existe Y ESTÁ BORRADO...
            if ($existingClient->trashed()) {
                // Devolvemos un error 409 (Conflicto) con los datos del cliente
                // para que la app pueda ofrecer restaurarlo.
                return response()->json([
                    'message' => 'Un cliente con este nombre ya existe en la papelera.',
                    'client' => $existingClient // Enviamos el cliente para restaurar
                ], Response::HTTP_CONFLICT); // 409
            } else {
                // 3. Si existe y NO está borrado, es un duplicado simple.
                return response()->json([
                    'message' => 'Un cliente con este nombre ya existe.'
                ], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
            }
        }

        // 4. Si no existe, lo creamos.
        $client = Client::create($validated);
        return response()->json(['data' => $client], Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        return response()->json(['data' => $client]);
    }

    /**
     * PUT /api/clients/{client}
     * Actualiza un cliente.
     */
    public function update(StoreClientRequest $request, Client $client)
    {
        $validated = $request->validated();

        $client->update($validated);

        return response()->json(['data' => $client->fresh()]);;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client) // 👈 MODIFICA ESTE MÉTODO
    {
        // Aquí podrías añadir validaciones (ej. no borrar si tiene pedidos)
        // if ($client->orders()->exists()) {
        //     return response()->json(['message' => 'No se puede eliminar un cliente con pedidos asociados.'], 409); // 409 Conflict
        // }

        $client->delete();

        // 204 No Content es la respuesta estándar para un DELETE exitoso
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * GET /api/clients/trashed
     * Muestra una lista de clientes en la papelera.
     */
    public function trashed()
    {
        // Busca solo los clientes que están en la papelera
        $trashedClients = Client::onlyTrashed()->orderBy('deleted_at', 'desc')->get();
        return response()->json(['data' => $trashedClients]);
    }

    /**
     * POST /api/clients/{id}/restore
     * Restaura un cliente desde la papelera.
     */
    public function restore($id) // No usamos Route-Model binding para poder buscar en borrados
    {
        $client = Client::withTrashed()->find($id);

        if (!$client) {
            return response()->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND); // 404
        }

        if (!$client->trashed()) {
            return response()->json(['message' => 'El cliente no está eliminado'], Response::HTTP_BAD_REQUEST); // 400
        }

        $client->restore();
        return response()->json(['data' => $client]); // Devuelve el cliente restaurado
    }
}
