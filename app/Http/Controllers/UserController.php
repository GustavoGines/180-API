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
    public function index()
    {
        // Excluye a clientes si tuvieras un rol 'client'
        $users = User::whereIn('role', ['admin', 'staff'])
                         ->orderBy('name')
                         ->get();
        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'], // admin o staff
        ]);

        // 👇 CAMBIO SUGERIDO: Devolver el modelo completo
        //    (como en show() y update()) para mantener consistencia.
        return response()->json([
            'data' => $user
        ], Response::HTTP_CREATED); // Usar el import Response::HTTP_CREATED
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
}

