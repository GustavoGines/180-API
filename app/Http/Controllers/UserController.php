<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest; // 👈 1. AÑADIDO
use App\Models\User;
use Illuminate\Http\Response; // 👈 2. AÑADIDO
use Illuminate\Support\Arr; // 👈 3. AÑADIDO
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /api/users
     * Muestra una lista de todos los usuarios (admin y staff).
     */
    /**
     * GET /api/users
     * Muestra una lista de todos los usuarios activos.
     */
    public function index()
    {
        // Solo usuarios NO eliminados (Soft Delete)
        $users = User::whereIn('role', ['admin', 'staff'])
                         ->orderBy('name')
                         ->get();
        return response()->json(['data' => $users]);
    }

    /**
     * GET /api/users/trashed
     * Muestra una lista de usuarios eliminados (Soft Delete).
     */
    public function trashed()
    {
        // Solo usuarios eliminados
        $trashedUsers = User::onlyTrashed()
                            ->whereIn('role', ['admin', 'staff'])
                            ->orderBy('deleted_at', 'desc')
                            ->get();
        return response()->json(['data' => $trashedUsers]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();
        $email = $validated['email'];

        // 🎯 Verificación de Conflicto (Usuario en Papelera)
        // Busca si el usuario existe pero está en soft-delete
        $trashedUser = User::onlyTrashed()->where('email', $email)->first();

        if ($trashedUser) {
            // Devuelve 409 Conflict con los datos del usuario borrado
            return response()->json([
                'message' => 'El usuario existe pero está inactivo.',
                'user' => $trashedUser
            ], Response::HTTP_CONFLICT); // 409
        }
        
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'], // admin o staff
        ]);

        return response()->json([
            'data' => $user
        ], Response::HTTP_CREATED); 
    }

    /**
     * GET /api/users/{user}
     * Muestra un usuario específico.
     */
    public function show(User $user)
    {
        return response()->json(['data' => $user]);
    }

     /**
     * PUT /api/users/{user}
     * Actualiza un usuario.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        $userData = Arr::except($validated, 'password');

        // Solo actualiza la contraseña si se envió una nueva
        if (!empty($validated['password'])) {
            $userData['password'] = Hash::make($validated['password']);
        }

        $user->update($userData);

        return response()->json(['data' => $user->fresh()]);
    }

    /**
     * DELETE /api/users/{user}
     * Elimina un usuario (Soft Delete).
     */
    public function destroy(User $user)
    {
        // Opcional: No permitir que un admin se borre a sí mismo
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes eliminarte a ti mismo.'], Response::HTTP_FORBIDDEN); // 403
        }
        
        // Asumiendo que el modelo User usa SoftDeletes
        $user->delete(); 
        
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // 🎯 NUEVO: POST /api/users/{id}/restore
    /**
     * Restaura un usuario que estaba en soft delete.
     */
    public function restore(int $id)
    {
        // Usamos withTrashed() para incluir los borrados
        $user = User::withTrashed()->findOrFail($id);

        if (!$user->trashed()) {
            return response()->json(['message' => 'El usuario no necesita ser restaurado.'], Response::HTTP_BAD_REQUEST);
        }

        $user->restore();

        // Devolvemos el usuario restaurado para actualizar la lista en el cliente
        return response()->json(['data' => $user->fresh()]);
    }

    // 🎯 NUEVO: DELETE /api/users/{id}/force-delete
    /**
     * Elimina permanentemente un usuario.
     */
    public function forceDelete(int $id)
    {
        // Solo puede ser eliminado permanentemente si está en la papelera
        $user = User::onlyTrashed()->findOrFail($id);
        
        $user->forceDelete();

        return response()->json(null, Response::HTTP_NO_CONTENT); // 204
    }
}

