use App\Http\Resources\UserResource; // Added import

// ...

    // --- Usuario ---
    Route::get('/user', fn (Request $request) => new UserResource($request->user()));
    Route::get('/me', fn () => new UserResource(auth()->user()));

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

    // --- Pedidos ---
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);

    // --- Estado del pedido---
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Para marcar como pagado totalmente
    Route::patch('/orders/{order}/mark-paid', [OrderController::class, 'markAsPaid']);
    Route::patch('/orders/{order}/mark-unpaid', [OrderController::class, 'markAsUnpaid']);

    // --- Dispositivos (FCM) ---
    Route::post('/devices/register', [DeviceController::class, 'store']);

    /**
     * Elimina un token de dispositivo (FCM) específico
     * perteneciente al usuario autenticado.
     */
    Route::post('/devices/unregister', function (Request $request) {

        // 1. Validar que nos enviaron el token
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        // 2. Buscar y borrar el token SOLO para el usuario
        //    que está haciendo la petición.
        $deletedCount = $request->user()->devices()
            ->where('fcm_token', $request->fcm_token)
            ->delete();

        if ($deletedCount > 0) {
            Log::info("Dispositivo des-registrado para usuario: {$request->user()->id}");

            return response()->json(['message' => 'Device unregistered successfully']);
        }

        Log::warning("Intento de des-registrar token no encontrado para usuario: {$request->user()->id}");

        return response()->json(['message' => 'Token not found or already unregistered'], 404);

    });

    // --- Rutas solo para Admins ---
    Route::middleware('can:admin')->group(function () {

        // 🎯 NUEVAS RUTAS DE GESTIÓN DE PAPELETA (USERS)
        Route::get('/users/trashed', [UserController::class, 'trashed']);
        Route::post('/users/{id}/restore', [UserController::class, 'restore']);
        Route::delete('/users/{id}/force-delete', [UserController::class, 'forceDelete']);

        // Esto crea automáticamente las 5 rutas del CRUD (index, store, show, update, destroy)
        Route::apiResource('users', UserController::class);
        // --- ADMIN CATALOG ROUTES ---
        Route::post('/admin/products', [AdminCatalogController::class, 'storeProduct']);
        Route::put('/admin/products/{id}', [AdminCatalogController::class, 'updateProduct']);
        Route::delete('/admin/products/{id}', [AdminCatalogController::class, 'destroyProduct']);

        Route::post('/admin/fillings', [AdminCatalogController::class, 'storeFilling']);
        Route::put('/admin/fillings/{id}', [AdminCatalogController::class, 'updateFilling']);
        Route::delete('/admin/fillings/{id}', [AdminCatalogController::class, 'destroyFilling']);

        Route::post('/admin/extras', [AdminCatalogController::class, 'storeExtra']);
        Route::put('/admin/extras/{id}', [AdminCatalogController::class, 'updateExtra']);
        Route::delete('/admin/extras/{id}', [AdminCatalogController::class, 'destroyExtra']); // END ADMIN CATALOG ROUTES
    });

    Route::get('/ping', function () {
        return response()->json(['status' => 'ok']);
    });
});
