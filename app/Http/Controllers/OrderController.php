<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\GoogleCalendarService; // ✅ Importación explícita
// ✅ Importación explícita
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            ->when($fromDate, fn ($q) => $q->whereDate('event_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('event_date', '<=', $toDate))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->paginate($request->query('per_page', 20));

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request)
    {
        // 1. Obtener datos validados (ya procesados por StoreOrderRequest)
        $validated = $request->validated();
        $files = $request->file('files') ?? [];

        // 4. Lógica para reemplazar Placeholders (Sigue siendo necesaria aquí)
        foreach ($validated['items'] as &$item) { // '&' (por referencia)
            if (isset($item['customization_json']['photo_urls']) && is_array($item['customization_json']['photo_urls'])) {
                $newUrls = [];
                foreach ($item['customization_json']['photo_urls'] as $url) {
                    if (str_starts_with($url, 'placeholder_') && isset($files[$url])) {
                        $file = $files[$url];
                        $path = $file->store('order-photos', 's3'); // Sube a R2
                        $newUrls[] = Storage::disk('s3')->url($path); // Obtiene URL de R2
                    } elseif (! str_starts_with($url, 'placeholder_')) {
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
            // Si viene is_paid, usarlo, si no false.
            $orderData['is_paid'] = $validated['is_paid'] ?? false;

            // 7. Crear la orden
            $order = Order::create($orderData);

            // 8. Crear los items
            if (! empty($validated['items'])) {
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
                Log::error("Error al crear evento de Google Calendar para la orden (nueva) {$order->id}: ".$e->getMessage());
            }

            $order->save();
        });

        return new OrderResource($order->load(['client', 'items']));
    }

    public function show(Order $order)
    {
        $order->load(['client', 'items', 'clientAddress']);

        return new OrderResource($order);
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        // 1. Obtener datos validados
        $validated = $request->validated();
        $files = $request->file('files') ?? [];

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
                        } elseif (! str_starts_with($url, 'placeholder_')) {
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
            if (! empty($urlsToDelete)) {
                // ✅ CAMBIO: Usar 's3' (R2)
                $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
                $pathsToDelete = [];
                foreach ($urlsToDelete as $url) {
                    if ($url && str_starts_with((string) $url, $r2BaseUrl)) {
                        $path = ltrim(substr((string) $url, strlen($r2BaseUrl)), '/');
                        if (! empty($path)) {
                            $pathsToDelete[] = $path;
                        }
                    }
                }
                if (! empty($pathsToDelete)) {
                    Log::info("[Update Order {$order->id}] Borrando archivos huérfanos de R2: ".implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('s3')->delete($pathsToDelete); // ✅ CAMBIO: Usar 's3'
                    } catch (\Exception $e) {
                        Log::error("[Update Order {$order->id}] Error borrando de R2: ".$e->getMessage());
                    }
                }
            }

            // 9. Sincronizar Google Calendar
            try {
                $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
            } catch (\Exception $e) {
                Log::error("Error al actualizar evento GC para orden {$order->id}: ".$e->getMessage());
            }

        });

        return new OrderResource($order->load(['client', 'items']));
    }

    public function updateStatus(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required_without:is_fully_paid', 'string', 'in:confirmed,ready,delivered,canceled'],
            'is_paid' => ['sometimes', 'boolean'], // Permitir actualizar solo el flag
            'is_fully_paid' => ['sometimes', 'required_without:status', 'boolean', 'accepted'],
        ]);

        $updated = false;

        if (isset($validated['status'])) {
            $order->status = $validated['status'];
            $updated = true;
        }

        if (isset($validated['is_paid'])) {
            $order->is_paid = $validated['is_paid'];
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

        return new OrderResource($order->fresh(['client', 'items']));
    }

    public function markAsPaid(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        if (! is_numeric($order->total) || $order->total <= 0 || $order->deposit >= $order->total) {
            return response()->json([
                'message' => 'El pedido ya está pagado o el total es inválido.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order->deposit = $order->total;
        $order->is_paid = true; // ✅ Forzar flag de pagado
        $order->save();

        try {
            $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
        } catch (\Exception $e) {
            Log::error("Error al actualizar evento GC (pago) {$order->id}: ".$e->getMessage());
        }

        return new OrderResource($order->fresh(['client', 'items']));
    }

    public function markAsUnpaid(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        $order->is_paid = false;
        // Si el depósito es igual al total, lo reseteamos a 0 asumiendo que fue marcado como pagado automáticamente.
        // Si es un pago parcial, no tocamos el depósito (aunque 'markAsPaid' lo hubiera sobrescrito, aquí no podemos saber el valor anterior).
        // Por seguridad en flujo "unmark", reseteamos si parece pagado total.
        if ($order->deposit >= $order->total) {
            $order->deposit = 0;
        }

        $order->save();

        try {
            $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
        } catch (\Exception $e) {
            Log::error("Error al actualizar evento GC (unmark-paid) {$order->id}: ".$e->getMessage());
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

            if (! empty($photoUrlsToDelete)) {
                // ✅ CAMBIO: Usar 's3' (R2)
                $r2BaseUrl = rtrim(Storage::disk('s3')->url(''), '/');
                $pathsToDelete = [];
                foreach ($photoUrlsToDelete as $url) {
                    if ($url && str_starts_with((string) $url, $r2BaseUrl)) {
                        $path = ltrim(substr((string) $url, strlen($r2BaseUrl)), '/');
                        if (! empty($path)) {
                            $pathsToDelete[] = $path;
                        }
                    } else {
                        Log::warning("[Destroy Order {$order->id}] URL R2 no reconocida: ".$url);
                    }
                }
                if (! empty($pathsToDelete)) {
                    Log::info("[Destroy Order {$order->id}] Borrando de R2: ".implode(', ', $pathsToDelete));
                    try {
                        Storage::disk('s3')->delete($pathsToDelete); // ✅ CAMBIO: Usar 's3'
                    } catch (\Exception $e) {
                        Log::error("[Destroy Order {$order->id}] Error borrando de R2: ".$e->getMessage());
                    }
                }
            }
            // --- FIN: LÓGICA BORRAR IMÁGENES ---

            // 4. Borrar evento de Google Calendar
            if (! empty($order->google_event_id)) {
                try {
                    $this->googleCalendarService->deleteEvent($order->google_event_id);
                } catch (\Exception $e) {
                    Log::error("[Destroy Order {$order->id}] Error borrando evento GC {$order->google_event_id}: ".$e->getMessage());
                }
            }

            // 5. Borrar el pedido de la base de datos
            $order->delete();
        });

        return response()->noContent();
    }
}
