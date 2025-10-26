<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(private GoogleCalendarService $googleCalendarService) {}

    /**
     * GET /api/orders?from=YYYY-MM-DD&to=YYYY-MM-DD&status=confirmed
     * Lista pedidos con filtros por rango de fecha y estado.
     */
    public function index(Request $request)
    {
        $fromDate = $request->query('from');      // YYYY-MM-DD
        $toDate = $request->query('to');        // YYYY-MM-DD
        $status = $request->query('status');    // confirmed,ready,delivered,canceled

        $orders = Order::query()
            ->with(['client', 'items'])
            ->when($fromDate, function ($builder) use ($fromDate) {
                $builder->whereDate('event_date', '>=', $fromDate);
            })
            ->when($toDate, function ($builder) use ($toDate) {
                $builder->whereDate('event_date', '<=', $toDate);
            })
            ->when($status, function ($builder) use ($status) {
                $builder->where('status', $status);
            })
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * POST /api/orders
     * Crea un pedido, sus items y el evento en Google Calendar (con recordatorios 24h/3h).
     */
    public function store(StoreOrderRequest $request)
    {
        $validated = $request->validated();
        $order = null;

        DB::transaction(function () use (&$order, $validated) {
            // 1. Guarda la seña original y prepara datos para la orden (con seña en 0)
            $originalDeposit = $validated['deposit'] ?? 0;
            $orderData = Arr::except($validated, ['items', 'deposit', 'status']);
            $orderData['deposit'] = 0;

            // Si no se especifica un estado, por defecto es 'confirmed'
            $orderData['status'] = $validated['status'] ?? 'confirmed';

            // 2. Crea la orden. Esto pasa el check (seña 0 <= total 0)
            $order = Order::create($orderData);

            // 3. Crea los items. Esto dispara tu trigger que calcula y guarda el 'total'.
            if (!empty($validated['items'])) {
                $order->items()->createMany($validated['items']);
            }

            // 4. Refresca el modelo para obtener el 'total' que fue calculado por el trigger
            $order->refresh();

            // 5. Ahora sí, actualiza la seña, asegurando que no sea mayor al total
            $order->deposit = min((float) $originalDeposit, (float) $order->total);

            // 6. Crear evento en Google Calendar y guardar el ID
            $googleEventId = $this->googleCalendarService->createFromOrder($order->fresh(['client', 'items']));
            $order->google_event_id = $googleEventId;
            
            // 7. Guarda la orden por última vez con la seña y el ID del evento correctos
            $order->save();
        });

        return response()->json($order->load(['client', 'items']), Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        // Laravel automáticamente encontrará el pedido con el ID de la URL.
        // Ahora, cargamos sus relaciones (cliente e ítems) para que viajen en el JSON.
        $order->load(['client', 'items']);

        // Devolvemos el pedido. Opcional pero recomendado: envuélvelo en un Resource.
        // Aquí lo devolvemos directamente para simplicidad.
        return response()->json($order);

        // Si usas API Resources (recomendado), se vería así:
        // return new OrderResource($order);
    }

    /**
     * PUT /api/orders/{order}
     * Actualiza un pedido, sus items y sincroniza el evento en Google Calendar.
     */
    public function update(StoreOrderRequest $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $order) {

            // --- INICIO: LÓGICA PARA BORRAR IMÁGENES ANTIGUAS ---
            // 1a. Obtener las URLs de las fotos ANTES de borrar los items
            $oldPhotoUrls = [];
            $order->load('items'); // Asegurarse de tener los items actuales cargados
            foreach ($order->items as $item) {
                $customizationData = $item->customization_json ?? [];
                if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                    $oldPhotoUrls = array_merge($oldPhotoUrls, $customizationData['photo_urls']);
                }
            }
            $oldPhotoUrls = array_unique($oldPhotoUrls);

            // 1b. Obtener las URLs de las fotos NUEVAS (las que vienen en la petición)
            $newPhotoUrls = [];
            if (isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $itemPayload) {
                    $customizationData = $itemPayload['customization_json'] ?? [];
                     if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                         $newPhotoUrls = array_merge($newPhotoUrls, $customizationData['photo_urls']);
                     }
                }
            }
            $newPhotoUrls = array_unique($newPhotoUrls);

            // 1c. Calcular las URLs a borrar (las que están en old pero no en new)
            $urlsToDelete = array_diff($oldPhotoUrls, $newPhotoUrls);
            // --- FIN: LÓGICA PARA BORRAR IMÁGENES ANTIGUAS ---


            // 2) Actualizar cabecera SIN items ni total
            $order->update(Arr::except($validated, ['items', 'total', 'deposit']));

            // 3) Evitar violación del CHECK mientras total queda en 0
            $originalDeposit = $validated['deposit'] ?? $order->deposit;
            $order->deposit = 0;      // o null
            $order->save();

            // 4) Reemplazar ítems
            $order->items()->delete(); // Borra los items viejos de la BD
            if (isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $itemPayload) {
                    $order->items()->create($itemPayload); // Crea los nuevos items
                }
            }


            // 5) Recalcular total (si tenés método o dejás que lo haga el trigger)
            // $order->recalculateTotals(); // si implementaste este método
            $order->refresh(); // tomar total actualizado por el trigger

            // 6) Ajustar depósito final (nunca mayor al total)
            if ($originalDeposit !== null) {
                $order->deposit = min((float) $originalDeposit, (float) $order->total);
                $order->save();
            }

            // --- INICIO: BORRAR FOTOS HUÉRFANAS DE SUPABASE ---
            if (!empty($urlsToDelete)) {
                $supabaseBaseUrl = rtrim(Storage::disk('supabase')->url(''), '/');
                $pathsToDelete = [];

                foreach ($urlsToDelete as $url) {
                    if ($url && str_starts_with((string)$url, $supabaseBaseUrl)) {
                        $path = ltrim(substr((string)$url, strlen($supabaseBaseUrl)), '/');
                        if (!empty($path)) {
                            $pathsToDelete[] = $path;
                        }
                    } else {
                        Log::warning("[Update Order {$order->id}] No se pudo extraer el path de Supabase para la URL a borrar: " . $url);
                    }
                }

                if (!empty($pathsToDelete)) {
                    Log::info("[Update Order {$order->id}] Borrando archivos huérfanos de Supabase: " . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('supabase')->delete($pathsToDelete);
                    } catch (\Exception $e) {
                        Log::error("[Update Order {$order->id}] Error al borrar archivos huérfanos de Supabase: " . $e->getMessage());
                    }
                }
            }
            // --- FIN: BORRAR FOTOS HUÉRFANAS DE SUPABASE ---


            // 7) Sincronizar Calendar (con try-catch)
            try {
                $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
            } catch (\Exception $e) {
                 Log::error("Error al actualizar evento de Google Calendar para la orden {$order->id}: " . $e->getMessage());
                 // No detenemos la transacción
            }

        });

        return response()->json($order->load(['client', 'items']));
    }

    public function updateStatus(Request $request, Order $order)
    {
        // 1. Autorización: Usamos el Gate que creamos.
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    
        // 2. Validación de los datos que pueden llegar
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:confirmed,ready,delivered,canceled'],
            'is_fully_paid' => ['sometimes', 'boolean'],
        ]);

        // 3. Lógica para actualizar el estado
        if (isset($validated['status'])) {
            $order->status = $validated['status'];
        }

        // 4. Lógica para marcar como pagado
        if (isset($validated['is_fully_paid']) && $validated['is_fully_paid'] === true) {
            $order->deposit = $order->total;
        }

        // 5. Guardar los cambios
        $order->save();

        // 6. Devolver la orden actualizada
        return response()->json($order->fresh(['client', 'items']));
    }

    public function uploadPhoto(Request $request)
    {
        // 1. Validar que se haya enviado un archivo y que sea una imagen
        $request->validate([
            'photo' => 'required|image|max:10240', // Límite de 10MB (10 * 1024)
        ]);
    
        // 2. Usamos el disco 'supabase'
        $path = $request->file('photo')->store('order-photos', 'supabase');
    
        // 3. Obtenemos la URL pública desde Supabase
        return response()->json([
            'url' => Storage::disk('supabase')->url($path)
        ]);
    }

    /**
     * DELETE /api/orders/{order}
     * Elimina el pedido y su evento en Google Calendar (si existe).
     */
    public function destroy(Order $order)
    {

        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        DB::transaction(function () use ($order) {
            // --- INICIO: LÓGICA PARA BORRAR IMÁGENES DE SUPABASE ---
            // 1. Cargar los items del pedido
            $order->load('items');

            // 2. Recopilar todas las URLs de las fotos de todos los items
            $photoUrlsToDelete = [];
            foreach ($order->items as $item) {
                // Asegúrate de que customization_json y photo_urls existan y sean un array
                $customizationData = $item->customization_json ?? []; // Usa un array vacío si es null
                if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                    $photoUrlsToDelete = array_merge($photoUrlsToDelete, $customizationData['photo_urls']);
                }
            }
            $photoUrlsToDelete = array_unique($photoUrlsToDelete); // Evitar borrar lo mismo dos veces

            // 3. Borrar cada foto de Supabase
            if (!empty($photoUrlsToDelete)) {
                $supabaseBaseUrl = rtrim(Storage::disk('supabase')->url(''), '/'); // Obtener la URL base del bucket
                $pathsToDelete = [];

                foreach ($photoUrlsToDelete as $url) {
                    // Extraer el path relativo a partir de la URL completa
                    if ($url && str_starts_with((string)$url, $supabaseBaseUrl)) {
                         // Elimina la URL base y el '/' inicial si existe
                        $path = ltrim(substr((string)$url, strlen($supabaseBaseUrl)), '/');
                        if (!empty($path)) {
                           $pathsToDelete[] = $path;
                        }
                    } else {
                        Log::warning("No se pudo extraer el path de Supabase para la URL: " . $url);
                    }
                }

                // Borrar los archivos en Supabase
                if (!empty($pathsToDelete)) {
                    Log::info('Borrando archivos de Supabase: ' . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('supabase')->delete($pathsToDelete);
                    } catch (\Exception $e) {
                         // Loguear error pero continuar
                        Log::error("Error al borrar archivos de Supabase: " . $e->getMessage());
                    }
                }
            }
            // --- FIN: LÓGICA PARA BORRAR IMÁGENES DE SUPABASE ---

            // 4. Borrar evento de Google Calendar (como antes, con try-catch mejorado)
            if (! empty($order->google_event_id)) {
                try {
                    $this->googleCalendarService->deleteEvent($order->google_event_id);
                } catch (\Exception $e) {
                    // Loguear el error pero continuar, para que el pedido se borre igual
                    Log::error("Error al borrar evento de Google Calendar {$order->google_event_id}: " . $e->getMessage());
                }
            }
            
            // 5. Borrar el pedido de la base de datos (como antes)
            $order->delete();
        });

        return response()->noContent();
    }
}
