<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Resources\UserResource;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;

class UserController extends Controller
{
    public function index()
    {
        $users = User::whereIn('role', ['admin', 'staff'])
                         ->orderBy('name')
                         ->get();
        return UserResource::collection($users);
    }

    public function trashed()
    {
        $trashedUsers = User::onlyTrashed()
                            ->whereIn('role', ['admin', 'staff'])
                            ->orderBy('deleted_at', 'desc')
                            ->get();
        return UserResource::collection($trashedUsers);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();
        $email = $validated['email'];

        $trashedUser = User::onlyTrashed()->where('email', $email)->first();

        if ($trashedUser) {
             return response()->json([
                'message' => 'El usuario existe pero está inactivo.',
                'user' => new UserResource($trashedUser)
            ], Response::HTTP_CONFLICT);
        }
        
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return new UserResource($user);
    }


    public function show(User $user)
    {
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();
        $userData = Arr::except($validated, 'password');

        if (!empty($validated['password'])) {
            $userData['password'] = Hash::make($validated['password']);
        }

        $user->update($userData);

        return new UserResource($user->fresh());
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->noContent();
    }

    public function restore(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        if (!$user->trashed()) {
            return response()->json(['message' => 'El usuario no necesita ser restaurado.'], Response::HTTP_BAD_REQUEST);
        }

        $user->restore();

        return new UserResource($user->fresh());
    }

    public function forceDelete(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->forceDelete();
        return response()->noContent();
    }
}
