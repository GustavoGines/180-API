<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private GoogleCalendarService $googleCalendarService) {}

    public function index(Request $request)
    {
        $fromDate = $request->query('from');
        $toDate = $request->query('to');
        $status = $request->query('status');

        $orders = Order::query()
            ->with(['client', 'items'])
            ->when($fromDate, fn($q) => $q->whereDate('event_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->whereDate('event_date', '<=', $toDate))
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->paginate($request->query('per_page', 20));

        return response()->json($orders);
    }

    public function store(Request $request) // 👈 CAMBIO: Usa Request
    {
        // 1. Obtener payload y archivos
        $payloadString = $request->input('order_payload');
        $validated = json_decode($payloadString, true) ?? [];
        $files = $request->file('files') ?? [];

        // 2. Validar el payload manualmente
        $validator = Validator::make($validated, [
            'client_id' => ['required', 'exists:clients,id'],
            'client_address_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(function() use ($validated) {
                    return ($validated['delivery_cost'] ?? 0) > 0;
                }), 
                Rule::exists('client_addresses', 'id')->where(function ($query) use ($validated) {
                    return $query->where('client_id', $validated['client_id'] ?? null);
                }),
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', 'string', 'in:confirmed,ready,delivered,canceled'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.base_price' => ['required', 'numeric', 'min:0'],
            'items.*.adjustments' => ['nullable', 'numeric'],
            'items.*.customization_notes' => ['nullable', 'string'],
            'items.*.customization_json' => ['nullable', 'array'],
        ]);

        // 3. Añadir validación 'after' (la que tenías en StoreOrderRequest)
        $validator->after(function ($validator) use ($validated) {
            $start = $validated['start_time'] ?? null;
            $end = $validated['end_time'] ?? null;
            if ($start && $end) {
                $startTime = \DateTime::createFromFormat('H:i', $start);
                $endTime = \DateTime::createFromFormat('H:i', $end);
                if ($startTime && $endTime && $endTime <= $startTime) {
                    $validator->errors()->add('end_time', 'La hora de fin debe ser posterior a la hora de inicio.');
                }
            }
            $items = $validated['items'] ?? [];
            $deliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            if (is_array($items) && ! empty($items)) {
                $calculatedItemsTotal = 0.0;
                foreach ($items as $key => $item) {
                    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 0;
                    $basePrice = isset($item['base_price']) && is_numeric($item['base_price']) ? (float) $item['base_price'] : -1.0; 
                    $adjustments = isset($item['adjustments']) && is_numeric($item['adjustments']) ? (float) $item['adjustments'] : 0.0; 
                    if ($qty <= 0 || $basePrice < 0) {
                        $validator->errors()->add("items.$key", 'El ítem tiene cantidad o precio base inválido.');
                        continue; 
                    }
                    $finalUnitPrice = $basePrice + $adjustments;
                    if ($finalUnitPrice < 0) {
                        $validator->errors()->add("items.$key", 'El precio final del ítem no puede ser negativo.');
                        continue;
                    }
                    $calculatedItemsTotal += $qty * $finalUnitPrice;
                }
                if (! $validator->errors()->has('items.*')) {
                    $calculatedGrandTotal = $calculatedItemsTotal + $deliveryCost;
                    $deposit = (float) ($validated['deposit'] ?? 0);
                    $epsilon = 0.01;
                    if ($deposit > ($calculatedGrandTotal + $epsilon)) {
                        $validator->errors()->add('deposit', 'El depósito no puede ser mayor al total.');
                    }
                }
            } elseif (($validated['deposit'] ?? 0) > 0) {
                $validator->errors()->add('deposit', 'No se puede registrar un depósito si no hay productos.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // 4. Lógica para reemplazar Placeholders
        foreach ($validated['items'] as &$item) { // '&' (por referencia)
            if (isset($item['customization_json']['photo_urls']) && is_array($item['customization_json']['photo_urls'])) {
                $newUrls = [];
                foreach ($item['customization_json']['photo_urls'] as $url) {
                    if (str_starts_with($url, 'placeholder_') && isset($files[$url])) {
                        $file = $files[$url];
                        $path = $file->store('order-photos', 's3'); // Sube a R2
                        $newUrls[] = Storage::disk('s3')->url($path); // Obtiene URL de R2
                    } elseif (!str_starts_with($url, 'placeholder_')) {
                        $newUrls[] = $url;
                    }
                }
                $item['customization_json']['photo_urls'] = $newUrls;
            }
        }
        unset($item);
        // --- FIN LÓGICA PLACEHOLDERS ---

        $order = null;

        DB::transaction(function () use (&$order, $validated) {
            // 5. Calcular el total (ahora $validated tiene las URLs correctas)
            $itemsTotal = 0.0;
            foreach ($validated['items'] as $item) {
                $qty = (int) $item['qty'];
                $basePrice = (float) $item['base_price'];
                $adjustments = (float) ($item['adjustments'] ?? 0); 
                $finalUnitPrice = $basePrice + $adjustments;
                $itemsTotal += $qty * $finalUnitPrice;
            }
            $deliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            $calculatedGrandTotal = $itemsTotal + $deliveryCost;

            // 6. Preparar datos de la orden
            $orderData = Arr::except($validated, ['items', 'deposit']);
            $orderData['total'] = $calculatedGrandTotal;
            $orderData['deposit'] = 0;
            $orderData['status'] = $validated['status'] ?? 'confirmed';

            // 7. Crear la orden
            $order = Order::create($orderData);

            // 8. Crear los items
            if (!empty($validated['items'])) {
                $itemsData = array_map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'qty' => $item['qty'],
                        'base_price' => $item['base_price'],
                        'adjustments' => $item['adjustments'] ?? 0,
                        'customization_notes' => $item['customization_notes'] ?? null,
                        'customization_json' => isset($item['customization_json']) && is_array($item['customization_json'])
                                                ? $item['customization_json']
                                                : null,
                    ];
                }, $validated['items']);
                $order->items()->createMany($itemsData);
            }

            // 9. Actualizar depósito
            $originalDeposit = (float) ($validated['deposit'] ?? 0);
            $order->deposit = min($originalDeposit, $calculatedGrandTotal);

            // 10. Crear evento en Google Calendar
            try {
                $googleEventId = $this->googleCalendarService->createFromOrder($order->fresh(['client', 'items']));
                $order->google_event_id = $googleEventId;
            } catch (\Exception $e) {
                Log::error("Error al crear evento de Google Calendar para la orden (nueva) {$order->id}: " . $e->getMessage());
            }

            $order->save();
        });

        return response()->json($order->load(['client', 'items']), Response::HTTP_CREATED);
    }


    public function show(Order $order)
    {
        $order->load(['client', 'items', 'clientAddress']);
        return response()->json($order);
    }

    public function update(Request $request, Order $order) // 👈 CAMBIO: Usa Request
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        // 1. Obtener payload y archivos
        $payloadString = $request->input('order_payload');
        $validated = json_decode($payloadString, true) ?? [];
        $files = $request->file('files') ?? [];

        // 2. Validar el payload manualmente
        $validator = Validator::make($validated, [
            'client_id' => ['required', 'exists:clients,id'],
            'client_address_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(function() use ($validated) {
                    return ($validated['delivery_cost'] ?? 0) > 0;
                }), 
                Rule::exists('client_addresses', 'id')->where(function ($query) use ($validated) {
                    return $query->where('client_id', $validated['client_id'] ?? null);
                }),
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:191'],
        ]);

        // 3. Añadir validación 'after'
        $validator->after(function ($validator) use ($validated) {
            // (Tu lógica de validación 'after' va aquí, igual que en 'store')
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($validated, $order, $files) {

            // --- INICIO: LÓGICA PARA BORRAR IMÁGENES ANTIGUAS ---
            $oldPhotoUrls = [];
            $order->load('items');
            foreach ($order->items as $item) {
                $customizationData = $item->customization_json ?? [];
                if (isset($customizationData['photo_urls']) && is_array($customizationData['photo_urls'])) {
                    $oldPhotoUrls = array_merge($oldPhotoUrls, $customizationData['photo_urls']);
                }
            }
            $oldPhotoUrls = array_unique($oldPhotoUrls);
            // --- FIN OBTENER URLs ANTIGUAS ---

            
            // 4. ✅ Lógica para reemplazar Placeholders (Igual que en 'store')
            foreach ($validated['items'] as &$item) { // 👈 '&' (por referencia)
                if (isset($item['customization_json']['photo_urls']) && is_array($item['customization_json']['photo_urls'])) {
                    $newUrls = [];
                    foreach ($item['customization_json']['photo_urls'] as $url) {
                        if (str_starts_with($url, 'placeholder_') && isset($files[$url])) {
                            $file = $files[$url];
                            $path = $file->store('order-photos', 's3');
                            $newUrls[] = Storage::disk('s3')->url($path);
                        } elseif (!str_starts_with($url, 'placeholder_')) {
                            $newUrls[] = $url; // Conservar URLs de red existentes
                        }
                    }
                    $item['customization_json']['photo_urls'] = $newUrls;
                }
            }
            unset($item);
            // --- FIN LÓGICA PLACEHOLDERS ---


            // --- INICIO: LÓGICA PARA OBTENER URLs A BORRAR (Modificada) ---
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
            // --- FIN LÓGICA OBTENER URLs A BORRAR ---


            // 5. Calcular el NUEVO total
            $newItemsTotal = 0.0;
            if (isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $item) {
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
            $newDeposit = (float) ($validated['deposit'] ?? 0);
            $orderData['total'] = $newCalculatedGrandTotal;
            $orderData['deposit'] = min($newDeposit, $newCalculatedGrandTotal);

            // 6. Actualizar la orden
            $order->update($orderData); 

            // 7. Reemplazar ítems
            $order->items()->delete(); // Borra los items viejos de la BD

            if (isset($validated['items']) && is_array($validated['items'])) {
                $itemsData = array_map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'qty' => $item['qty'],
                        'base_price' => $item['base_price'],
                        'adjustments' => $item['adjustments'] ?? 0,
                        'customization_notes' => $item['customization_notes'] ?? null,
                        'customization_json' => isset($item['customization_json']) && is_array($item['customization_json'])
                                                ? $item['customization_json']
                                                : null,
                    ];
                }, $validated['items']);
                $order->items()->createMany($itemsData); // Crea los nuevos items
            }
            
            // 8. Ejecutar el borrado de archivos de R2 (disco 's3')
            if (!empty($urlsToDelete)) {
                // ✅ CAMBIO: Usar 's3' (R2)
                $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
                $pathsToDelete = [];
                foreach ($urlsToDelete as $url) {
                    if ($url && str_starts_with((string)$url, $r2BaseUrl)) {
                        $path = ltrim(substr((string)$url, strlen($r2BaseUrl)), '/');
                        if (!empty($path)) $pathsToDelete[] = $path;
                    }
                }
                if (!empty($pathsToDelete)) {
                    Log::info("[Update Order {$order->id}] Borrando archivos huérfanos de R2: " . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('s3')->delete($pathsToDelete); // ✅ CAMBIO: Usar 's3'
                    } catch (\Exception $e) {
                        Log::error("[Update Order {$order->id}] Error borrando de R2: " . $e->getMessage());
                    }
                }
            }
            
            // 9. Sincronizar Google Calendar
            try {
                $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
            } catch (\Exception $e) {
                Log::error("Error al actualizar evento GC para orden {$order->id}: " . $e->getMessage());
            }

        });

        return response()->json($order->load(['client', 'items']));
    }

    public function updateStatus(Request $request, Order $order)
    {
        if (!Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required_without:is_fully_paid', 'string', 'in:confirmed,ready,delivered,canceled'],
            'is_fully_paid' => ['sometimes', 'required_without:status', 'boolean', 'accepted'],
        ]);

        $updated = false;

        if (isset($validated['status'])) {
            $order->status = $validated['status'];
            $updated = true;
        }

        if (isset($validated['is_fully_paid']) && $validated['is_fully_paid'] === true) {
            if (is_numeric($order->total)) {
                $order->deposit = $order->total;
                $updated = true;
            } else {
                Log::error("Intento de marcar como pagada la orden {$order->id} pero el total no es numérico.");
            }
        }

        if ($updated) {
            $order->save();
        }

        return response()->json($order->fresh(['client', 'items']));
    }

    public function markAsPaid(Request $request, Order $order)
    {
        if (!Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        if (!is_numeric($order->total) || $order->total <= 0 || $order->deposit >= $order->total) {
            return response()->json([
                'message' => 'El pedido ya está pagado o el total es inválido.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order->deposit = $order->total;
        $order->save();

        try {
            $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
        } catch (\Exception $e) {
            Log::error("Error al actualizar evento GC (pago) {$order->id}: " . $e->getMessage());
        }

        return response()->json($order->fresh(['client', 'items']));
    }

    public function destroy(Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        DB::transaction(function () use ($order) {
            // --- Lógica para borrar imágenes de R2 ---
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
                // ✅ CAMBIO: Usar 's3' (R2)
                $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
                $pathsToDelete = [];
                foreach ($photoUrlsToDelete as $url) {
                    if ($url && str_starts_with((string)$url, $r2BaseUrl)) {
                        $path = ltrim(substr((string)$url, strlen($r2BaseUrl)), '/');
                        if (!empty($path)) $pathsToDelete[] = $path;
                    } else {
                        Log::warning("[Destroy Order {$order->id}] URL R2 no reconocida: " . $url);
                    }
                }
                if (!empty($pathsToDelete)) {
                    Log::info("[Destroy Order {$order->id}] Borrando de R2: " . implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('s3')->delete($pathsToDelete); // ✅ CAMBIO: Usar 's3'
                    } catch (\Exception $e) {
                        Log::error("[Destroy Order {$order->id}] Error borrando de R2: " . $e->getMessage());
                    }
                }
            }
            // --- FIN: LÓGICA BORRAR IMÁGENES ---

            // 4. Borrar evento de Google Calendar
            if (! empty($order->google_event_id)) {
                try {
                    $this->googleCalendarService->deleteEvent($order->google_event_id);
                } catch (\Exception $e) {
                    Log::error("[Destroy Order {$order->id}] Error borrando evento GC {$order->google_event_id}: " . $e->getMessage());
                }
            }

            // 5. Borrar el pedido de la base de datos
            $order->delete();
        });

        return response()->noContent();
    }
}

