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
use Illuminate\Support\Facades\Http;

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


Route::get('/test-supabase-v2', function () {
    // Usamos la variable REGION que ya tiene el ID del proyecto (ej: zdzkotlpixfhrcidetvb)
    $projectRef = env('SUPABASE_REGION'); 
    $apiKey = env('SUPABASE_SECRET');

    if (empty($projectRef) || empty($apiKey)) {
        return response()->json(['error' => 'Faltan SUPABASE_REGION y/o SUPABASE_SECRET.'], 500);
    }

    // Construimos la URL correcta para la API de Storage
    $apiUrl = "https://{$projectRef}.supabase.co/storage/v1/bucket";

    try {
        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Authorization' => 'Bearer ' . $apiKey,
        ])->get($apiUrl);

        // Devolvemos el status, las cabeceras y el cuerpo crudo para tener más detalles
        return response()->json([
            'status' => $response->status(),
            'headers' => $response->headers(),
            'raw_body' => $response->body(), 
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Falló la conexión a Supabase.',
            'message' => $e->getMessage(),
        ], 500);
    }
});