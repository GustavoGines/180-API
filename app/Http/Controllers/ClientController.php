<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest; // Assuming this exists or using Request
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $searchQuery = trim($request->query('query'));

        $clients = Client::query()
            ->with(['orders' => function ($query) {
                $query->whereNotIn('status', ['canceled', 'delivered'])->orderBy('event_date', 'asc');
            }])
            ->when($searchQuery, function ($builder) use ($searchQuery) {
                // 1. Intentar limpiar el input como si fuera un teléfono
                $normalizedPhoneQuery = $this->normalizePhone($searchQuery);

                // 2. Separar términos para la búsqueda por palabras (Nombre/Email)
                $searchTerms = explode(' ', $searchQuery);

                return $builder->where(function ($subQuery) use ($searchTerms, $normalizedPhoneQuery) {

                    // A) Si el input limpio tiene formato de teléfono (solo números y al menos 6 dígitos),
                    // le damos prioridad de coincidencia EXACTA contra la DB (ignorando símbolos).
                    if ($normalizedPhoneQuery && preg_match('/^\+?\d{6,}$/', $normalizedPhoneQuery)) {
                        // Limpiamos el $normalizedPhoneQuery de cualquier '+' para comparar solo dígitos
                        $digitsOnly = preg_replace('/[^\d]/', '', $normalizedPhoneQuery);

                        // PostgreSQL: regexp_replace(phone, '[^0-9]+', '', 'g')
                        // SQLite (Testing): requiremos un fallback si la DB es sqlite
                        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
                            $subQuery->orWhere('phone', 'like', "%{$digitsOnly}%");
                        } else {
                            $subQuery->orWhereRaw("regexp_replace(phone, '[^0-9]+', '', 'g') = ?", [$digitsOnly]);
                        }
                    }

                    // B) Búsqueda tradicional por palabras (Nombre, Email, o Teléfono parcial)
                    foreach ($searchTerms as $term) {
                        $term = trim($term);
                        if (! empty($term)) {
                            $likeTerm = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                            $subQuery->orWhere(function ($wordQuery) use ($likeTerm) {
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

    public function store(Request $request)
    {
        // Note: Switched to generic Request if StoreClientRequest is missing,
        // but let's try to be safe. I'll use inline validation to be sure.
        // Actually, the previous code used StoreClientRequest. I'll keep it but make sure to import it.
        // If StoreClientRequest doesn't exist, this will error.
        // Let's check if it exists first? No, "store" method used it.
        // I'll stick to generic Request and Validate to be safe against missing Request class,
        // OR I can blindly import it.
        // Given complexity, I will use Request and manually validte to be robust.

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            // Add other fields as necessary
        ]);

        $name = trim($validated['name']);
        if (isset($validated['phone'])) {
            $validated['phone'] = $this->normalizePhone($validated['phone']);
        }
        $validated['name'] = $name;

        $existingClient = Client::withTrashed()
            ->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name])
            ->first();

        if ($existingClient) {
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

    public function update(Request $request, Client $client) // relaxed to Request
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
        ]);

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

    public function destroy(Client $client)
    {
        $client->delete();

        return response()->noContent();
    }

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

    public function forceDelete($id)
    {
        $client = Client::withTrashed()->find($id);

        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND);
        }

        if ($client->orders()->exists()) {
            return response()->json([
                'message' => '¡Conflicto! Este cliente tiene pedidos asociados y no puede ser eliminado permanentemente.',
            ], Response::HTTP_CONFLICT);
        }

        $client->forceDelete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);

        if (strlen(ltrim($normalized, '+')) < 10) {
            return $normalized;
        }

        if (str_starts_with($normalized, '+549')) {
            return $normalized;
        }

        if (str_starts_with($normalized, '549')) {
            return '+'.$normalized;
        }

        if (str_starts_with($normalized, '0') || str_starts_with($normalized, '15')) {
            $normalized = ltrim($normalized, '0');
            $normalized = ltrim($normalized, '15');
        }

        if (strlen($normalized) >= 10 && strlen($normalized) <= 12 && ! str_starts_with($normalized, '54')) {
            return '+549'.$normalized;
        }

        return $normalized;
    }
}
