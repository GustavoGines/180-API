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
                // 'LIKE' es universal (MySQL, PostgreSQL, etc.)
                // Escapamos los caracteres
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $searchQuery).'%';                
                // Usamos unaccent() para ignorar tildes E ILIKE para ignorar mayúsculas
                $builder->where(function ($subquery) use ($like) {
                    $subquery->whereRaw('unaccent(name) ILIKE unaccent(?)', [$like])
                        ->orWhereRaw('unaccent(phone) ILIKE unaccent(?)', [$like])
                        ->orWhereRaw('unaccent(email) ILIKE unaccent(?)', [$like]);
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
        // 1. NORMALIZA: Quita espacios al inicio/final
        $name = trim($validated['name']);

        // 2. COMPARA: Revisa si existe usando unaccent() y LOWER()
        $existingClient = Client::withTrashed()
            ->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])
            ->first();

        if ($existingClient) {
            // ... (Tu lógica de 409 y 422 para restaurar o avisar de duplicado está perfecta)
            if ($existingClient->trashed()) {
                return response()->json([
                    'message' => 'Un cliente con este nombre ya existe en la papelera.',
                    'client' => $existingClient
                ], Response::HTTP_CONFLICT); // 409
            } else {
                return response()->json([
                    'message' => 'Un cliente con este nombre ya existe.'
                ], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
            }
        }

        // 4. CREA: Usa el nombre ya "trimeado"
        $validated['name'] = $name; 
        $client = Client::create($validated);
        
        return response()->json(['data' => $client], Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        $client->load('addresses');

        return response()->json(['data' => $client]);
    }

    /**
     * PUT /api/clients/{client}
     * Actualiza un cliente.
     */
    public function update(StoreClientRequest $request, Client $client)
    {
        $validated = $request->validated();
        
        // 1. NORMALIZA: Quita espacios al inicio/final
        $name = trim($validated['name']);

        // 2. COMPARA: Revisa si existe usando unaccent() y LOWER()
        $existingClient = Client::withTrashed()
            ->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])
            // ¡Esta es la condición clave para UPDATE!
            // Solo busca conflictos si el ID encontrado es DIFERENTE al actual
            ->where('id', '!=', $client->id) 
            ->first();

        // 3. MANEJA CONFLICTOS (Idéntico a store)
        if ($existingClient) {
            if ($existingClient->trashed()) {
                return response()->json([
                    'message' => 'Otro cliente con este nombre ya existe en la papelera.',
                    'client' => $existingClient
                ], Response::HTTP_CONFLICT); // 409
            } else {
                return response()->json([
                    'message' => 'Otro cliente con este nombre ya existe.'
                ], Response::HTTP_UNPROCESSABLE_ENTITY); // 422
            }
        }

        // 4. ACTUALIZA: Usa el nombre ya "trimeado"
        $validated['name'] = $name; 
        $client->update($validated);

        return response()->json(['data' => $client->fresh()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client) 
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

    /**
     * DELETE /api/clients/{id}/force-delete
     * Elimina permanentemente un cliente de la base de datos.
     */
    public function forceDelete($id)
    {
        // 1. Buscamos al cliente INCLUYENDO los de la papelera
        $client = Client::withTrashed()->find($id);

        if (!$client) {
            return response()->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND);
        }

        // 2. ¡MUY IMPORTANTE! Revalidar que no tenga pedidos.
        // NO DEBERÍAS borrar permanentemente un cliente con historial de ventas.
        if ($client->orders()->exists()) {
            return response()->json([
                'message' => '¡Conflicto! Este cliente tiene pedidos asociados y no puede ser eliminado permanentemente.'
            ], Response::HTTP_CONFLICT); // 409
        }

        // 3. Si no tiene pedidos, ahora sí, borrado físico.
        $client->forceDelete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
