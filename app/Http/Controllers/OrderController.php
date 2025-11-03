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
        $fromDate = $request->query('from');
        $toDate = $request->query('to');
        $status = $request->query('status');

        $orders = Order::query()
            ->with(['client', 'items']) // Asegúrate que el modelo Order tenga la relación 'items' bien definida
            ->when($fromDate, fn($q) => $q->whereDate('event_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->whereDate('event_date', '<=', $toDate))
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->paginate($request->query('per_page', 20)); // Permitir paginación configurable

        return response()->json($orders);
    }

    /**
     * POST /api/orders
     * Crea un pedido, sus items y el evento en Google Calendar.
     * El total se calcula basado en los items + delivery_cost.
     */
    public function store(StoreOrderRequest $request)
    {
        $validated = $request->validated();
        $order = null;

        DB::transaction(function () use (&$order, $validated) {
            // 1. Calcular el total basado en los items recibidos y el costo de envío
            $itemsTotal = 0.0;
            foreach ($validated['items'] as $item) {
                $qty = (int) $item['qty'];
                $basePrice = (float) $item['base_price'];
                // adjustments es 'nullable' en la request, si no existe usamos 0
                $adjustments = (float) ($item['adjustments'] ?? 0); 
                
                $finalUnitPrice = $basePrice + $adjustments;
            
                $itemsTotal += $qty * $finalUnitPrice; // Suma usando el precio final calculado
            }
            $deliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            $calculatedGrandTotal = $itemsTotal + $deliveryCost;

            // 2. Preparar datos de la orden principal
            $orderData = Arr::except($validated, ['items', 'deposit']);
            $orderData['total'] = $calculatedGrandTotal; // Guardar el total calculado
            $orderData['deposit'] = 0; // Inicializar depósito en 0

            $orderData['status'] = $validated['status'] ?? 'confirmed';

            // 3. Crear la orden (sin los items aún)
            $order = Order::create($orderData);

            // 4. Crear los items asociados a la orden
            if (!empty($validated['items'])) {
                // Mapear para asegurar que solo los campos correctos se pasen a createMany
                $itemsData = array_map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'qty' => $item['qty'],
                        'base_price' => $item['base_price'],        // ✅ BASE PRICE
                        'adjustments' => $item['adjustments'] ?? 0, // ✅ ADJUSTMENTS
                        'customization_notes' => $item['customization_notes'] ?? null, // ✅ NOTES
                        'customization_json' => isset($item['customization_json']) && is_array($item['customization_json'])
                                                ? $item['customization_json']
                                                : null,
                    ];
                }, $validated['items']);
                $order->items()->createMany($itemsData);
            }

            // 5. Ahora sí, actualiza la seña, asegurando que no sea mayor al total calculado
            $originalDeposit = (float) ($validated['deposit'] ?? 0);
            $order->deposit = min($originalDeposit, $calculatedGrandTotal);

            // 6. Crear evento en Google Calendar y guardar el ID (con try-catch)
            try {
                $googleEventId = $this->googleCalendarService->createFromOrder($order->fresh(['client', 'items']));
                $order->google_event_id = $googleEventId;
            } catch (\Exception $e) {
                Log::error("Error al crear evento de Google Calendar para la orden (nueva) {$order->id}: " . $e->getMessage());
            }

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
        $order->load(['client', 'items', 'clientAddress']);
        return response()->json($order);
    }

    /**
     * PUT /api/orders/{order}
     * Actualiza un pedido, sus items, sincroniza Google Calendar y borra fotos antiguas de Supabase.
     * El total se recalcula basado en los nuevos items + delivery_cost.
     */
    public function update(StoreOrderRequest $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $order) {

            // --- INICIO: LÓGICA PARA BORRAR IMÁGENES ANTIGUAS (Sin cambios) ---
            $oldPhotoUrls = [];
            $order->load('items');
            foreach ($order->items as $item) {
                $customizationData = $item->customization_json ?? [];
                if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                    $oldPhotoUrls = array_merge($oldPhotoUrls, $customizationData['photo_urls']);
                }
            }
            $oldPhotoUrls = array_unique($oldPhotoUrls);

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
            $urlsToDelete = array_diff($oldPhotoUrls, $newPhotoUrls);
            // --- FIN: LÓGICA PARA BORRAR IMÁGENES ANTIGUAS ---


            // 1. Calcular el NUEVO total basado en los items recibidos y el costo de envío
            $newItemsTotal = 0.0;
             if (isset($validated['items']) && is_array($validated['items'])) {
                 foreach ($validated['items'] as $item) {
                      // 👇 ACTUALIZADO: Usar base_price + adjustments para el cálculo
                      $qty = (int) $item['qty'];
                      $basePrice = (float) $item['base_price'];
                      $adjustments = (float) ($item['adjustments'] ?? 0); 
                      $finalUnitPrice = $basePrice + $adjustments;

                      $newItemsTotal += $qty * $finalUnitPrice;
                 }
             }
            $newDeliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            $newCalculatedGrandTotal = $newItemsTotal + $newDeliveryCost;

            $orderData = Arr::except($validated, ['items', 'deposit']);

            // 3. (NUEVO) Calcular el depósito ANTES de actualizar
            $newDeposit = (float) ($validated['deposit'] ?? 0); // Usar el depósito que viene en la request
            
            // 4. (NUEVO) Añadir el total Y el depósito al array de datos
            $orderData['total'] = $newCalculatedGrandTotal;
            $orderData['deposit'] = min($newDeposit, $newCalculatedGrandTotal);

            // 5. Actualizar la orden CON AMBOS VALORES a la vez
            $order->update($orderData); 

            // 6. Reemplazar ítems
            $order->items()->delete(); // Borra los items viejos de la BD

             if (isset($validated['items']) && is_array($validated['items'])) {
                 $itemsData = array_map(function ($item) {
                      return [
                           'name' => $item['name'],
                           'qty' => $item['qty'],
                           'base_price' => $item['base_price'],        // ✅ BASE PRICE
                           'adjustments' => $item['adjustments'] ?? 0, // ✅ ADJUSTMENTS
                           'customization_notes' => $item['customization_notes'] ?? null, // ✅ NOTES
                           'customization_json' => isset($item['customization_json']) && is_array($item['customization_json'])
                                                 ? $item['customization_json']
                                                 : null,
                      ];
                 }, $validated['items']);
                 $order->items()->createMany($itemsData); // Crea los nuevos items
             }
            
            if (!empty($urlsToDelete)) {
                $supabaseBaseUrl = rtrim(Storage::disk('supabase')->url(''), '/');
                $pathsToDelete = [];
                foreach ($urlsToDelete as $url) {
                     if ($url && str_starts_with((string)$url, $supabaseBaseUrl)) {
                         $path = ltrim(substr((string)$url, strlen($supabaseBaseUrl)), '/');
                         if (!empty($path)) $pathsToDelete[] = $path;
                     } else {
                         Log::warning("[Update Order {$order->id}] URL huérfana no reconocida: " . $url);
                     }
                }
                if (!empty($pathsToDelete)) {
                    Log::info("[Update Order {$order->id}] Borrando archivos huérfanos: " . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('supabase')->delete($pathsToDelete);
                    } catch (\Exception $e) {
                        Log::error("[Update Order {$order->id}] Error borrando de Supabase: " . $e->getMessage());
                    }
                }
            }
            try {
                $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
            } catch (\Exception $e) {
                Log::error("Error al actualizar evento GC para orden {$order->id}: " . $e->getMessage());
            }

        });

        return response()->json($order->load(['client', 'items']));
    }

    /**
     * PATCH /api/orders/{order}/status
     * Actualiza solo el estado o marca como pagado completamente.
     */
    public function updateStatus(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required_without:is_fully_paid', 'string', 'in:confirmed,ready,delivered,canceled'],
            'is_fully_paid' => ['sometimes', 'required_without:status', 'boolean', 'accepted'], // 'accepted' significa que debe ser true si se envía
        ]);

        $updated = false;

        // 3. Lógica para actualizar el estado
        if (isset($validated['status'])) {
            $order->status = $validated['status'];
            $updated = true;
        }

        // 4. Lógica para marcar como pagado (is_fully_paid debe ser true si se envió)
        if (isset($validated['is_fully_paid']) && $validated['is_fully_paid'] === true) {
            if (is_numeric($order->total)) {
                 $order->deposit = $order->total;
                 $updated = true;
            } else {
                 Log::error("Intento de marcar como pagada la orden {$order->id} pero el total no es numérico ({$order->total})");
            }
        }

        // 5. Guardar los cambios solo si hubo alguno
        if ($updated) {
            $order->save();
        }

        // 6. Devolver la orden actualizada
        return response()->json($order->fresh(['client', 'items']));
    }

    /**
     * PATCH /api/orders/{order}/mark-paid
     * Actualiza el depósito al valor total para marcar como completamente pagado.
     */
    public function markAsPaid(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
        
        // 1. Verificar si el total es numérico y la orden no está ya pagada.
        if (! is_numeric($order->total) || $order->total <= 0 || $order->deposit >= $order->total) {
             // Devolver un error 422 si no es posible marcar como pagado
             return response()->json([
                 'message' => 'El pedido ya está pagado o el total es inválido.',
             ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 2. Actualizar el depósito
        $order->deposit = $order->total;
        $order->save();

        // 3. Opcional: Sincronizar Calendar (ya que el total podría ser relevante)
        try {
            $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
        } catch (\Exception $e) {
            Log::error("Error al actualizar evento GC (pago) para orden {$order->id}: " . $e->getMessage());
        }

        // 4. Devolver la orden actualizada
        return response()->json($order->fresh(['client', 'items']));
    }

    /**
     * POST /api/orders/upload-photo
     * Sube una foto a Supabase y devuelve la URL.
     */
    public function uploadPhoto(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|max:10240', // 10MB
        ]);

        try {
            $path = $request->file('photo')->store('order-photos', 'supabase');

            if (!$path) {
                 throw new \Exception("Supabase storage returned an empty path.");
            }

            return response()->json([
                'url' => Storage::disk('supabase')->url($path)
            ]);
        } catch (\Exception $e) {
            Log::error("Error al subir foto a Supabase: " . $e->getMessage());
            return response()->json(['message' => 'Error al subir la imagen al servidor.'], 500);
        }
    }

    /**
     * DELETE /api/orders/{order}
     * Elimina el pedido, su evento en Google Calendar y las fotos asociadas en Supabase.
     */
    public function destroy(Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        DB::transaction(function () use ($order) {
            // --- INICIO: LÓGICA PARA BORRAR IMÁGENES DE SUPABASE (Sin cambios) ---
            $order->load('items');
            $photoUrlsToDelete = [];
            foreach ($order->items as $item) {
                $customizationData = $item->customization_json ?? [];
                if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                    $photoUrlsToDelete = array_merge($photoUrlsToDelete, $customizationData['photo_urls']);
                }
            }
            $photoUrlsToDelete = array_unique($photoUrlsToDelete);

            if (!empty($photoUrlsToDelete)) {
                $supabaseBaseUrl = rtrim(Storage::disk('supabase')->url(''), '/');
                $pathsToDelete = [];
                foreach ($photoUrlsToDelete as $url) {
                     if ($url && str_starts_with((string)$url, $supabaseBaseUrl)) {
                         $path = ltrim(substr((string)$url, strlen($supabaseBaseUrl)), '/');
                         if (!empty($path)) $pathsToDelete[] = $path;
                     } else {
                         Log::warning("[Destroy Order {$order->id}] URL Supabase no reconocida: " . $url);
                     }
                }
                if (!empty($pathsToDelete)) {
                    Log::info("[Destroy Order {$order->id}] Borrando de Supabase: " . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('supabase')->delete($pathsToDelete);
                    } catch (\Exception $e) {
                        Log::error("[Destroy Order {$order->id}] Error borrando de Supabase: " . $e->getMessage());
                    }
                }
            }
            // --- FIN: LÓGICA BORRAR IMÁGENES ---

            // 4. Borrar evento de Google Calendar (Sin cambios)
            if (! empty($order->google_event_id)) {
                try {
                    $this->googleCalendarService->deleteEvent($order->google_event_id);
                } catch (\Exception $e) {
                    Log::error("[Destroy Order {$order->id}] Error borrando evento GC {$order->google_event_id}: " . $e->getMessage());
                }
            }

            // 5. Borrar el pedido de la base de datos (Sin cambios)
            $order->delete();
        });

        return response()->noContent();
    }
}