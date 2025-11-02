<?php

use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ClientAddressController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Públicas (No requieren autenticación)
|--------------------------------------------------------------------------
*/

// Login personalizado para Sanctum
Route::post('/auth/token', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
        'device_name' => ['required', 'string'],
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales invalidas'], 422);
    }

    return response()->json(['token' => $user->createToken($request->device_name)->plainTextToken]);
});

// Rutas para restablecer contraseña
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('guest')->name('password.email');
Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('guest')->name('password.store');


/*
|--------------------------------------------------------------------------
| Rutas Protegidas (Requieren token de Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // --- Usuario ---
    Route::get('/user', fn(Request $request) => $request->user());
    Route::get('/me', fn() => auth()->user());

    // --- Clientes ---
    Route::get('/clients/trashed', [ClientController::class, 'trashed']);
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);
    Route::post('/clients/{id}/restore', [ClientController::class, 'restore']);
    Route::delete('/clients/{id}/force-delete', [ClientController::class, 'forceDelete']);

    // (Están "anidadas" bajo un cliente)
    Route::prefix('clients/{client}')->group(function () {
        // POST /api/clients/1/addresses
        Route::post('addresses', [ClientAddressController::class, 'store']);
        // PUT /api/clients/1/addresses/5
        Route::put('addresses/{address}', [ClientAddressController::class, 'update']);
        // DELETE /api/clients/1/addresses/5
        Route::delete('addresses/{address}', [ClientAddressController::class, 'destroy']);
    });

    // --- Pedidos ---W
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::post('/orders/upload-photo', [OrderController::class, 'uploadPhoto']);
    
    // --- Estado del pedido---
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // --- Dispositivos (FCM) ---
    Route::post('/devices/register', [DeviceController::class, 'store']);
    
    // --- Rutas solo para Admins ---
    Route::middleware('can:admin')->group(function () {
        // Esto crea automáticamente las 5 rutas del CRUD
        // (index, store, show, update, destroy)
        Route::apiResource('users', UserController::class);
    });

    Route::get('/ping', function () {
    return response()->json(['status' => 'ok']);
});
});