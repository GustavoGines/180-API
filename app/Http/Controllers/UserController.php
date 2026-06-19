<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index()
    {
        $users = User::whereIn('role', ['admin', 'staff'])
            ->orderBy('name')
            ->get();

        return UserResource::collection($users);
    }

    public function updateProfile(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'avatar' => 'nullable|image|max:5120', // max 5MB
        ]);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_url) {
                $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
                if (str_starts_with($user->avatar_url, $r2BaseUrl)) {
                    $oldPath = ltrim(substr($user->avatar_url, strlen($r2BaseUrl)), '/');
                    Storage::disk('s3')->delete($oldPath);
                }
            }

            $path = $request->file('avatar')->store('profile-photos', 's3');
            $validated['avatar_url'] = Storage::disk('s3')->url($path);
        }

        $user->update(Arr::except($validated, ['avatar']));
        return new UserResource($user);
    }

    public function updatePassword(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta.',
                'errors' => ['current_password' => ['La contraseña no coincide con nuestros registros.']]
            ], \Illuminate\Http\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->update([
            'password' => Hash::make($validated['new_password'])
        ]);

        return response()->json(['message' => 'Contraseña actualizada correctamente']);
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
                'user' => new UserResource($trashedUser),
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

        if (! empty($validated['password'])) {
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

        if (! $user->trashed()) {
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
