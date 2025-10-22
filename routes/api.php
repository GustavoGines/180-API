<?php

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

// Breeze API ya provee /login, /register, /user, /logout
Route::middleware('auth:sanctum')->group(function () {
    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/upload-photo', [OrderController::class, 'uploadPhoto']);

    // Devices (FCM)
    Route::post('/devices/register', [DeviceController::class, 'store']);
    
});

Route::post('/auth/token', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
        'device_name' => ['required', 'string'], // ej: "postman"
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales invalidas'], 422);
    }

    $token = $user->createToken($request->device_name)->plainTextToken;

    return response()->json(['token' => $token]);
});

Route::middleware(['auth:sanctum', 'can:admin'])->group(function () {
    Route::post('/users', [UserController::class, 'store']); // crear usuarios
});

Route::middleware('auth:sanctum')->get('/me', fn () => auth()->user());

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('guest')
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest')
    ->name('password.store');

    use Illuminate\Support\Facades\Http;

Route::get('/test-supabase', function () {
    $projectUrl = rtrim(env('SUPABASE_ENDPOINT', ''), '/');
    $apiKey = env('SUPABASE_SECRET');

    if (empty($projectUrl) || empty($apiKey)) {
        return response()->json(['error' => 'Variables de Supabase no configuradas.'], 500);
    }

    try {
        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Authorization' => 'Bearer ' . $apiKey,
        ])->get($projectUrl . '/storage/v1/bucket');

        return response()->json([
            'status' => $response->status(),
            'body' => $response->json(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Falló la conexión a Supabase.',
            'message' => $e->getMessage(),
        ], 500);
    }
});