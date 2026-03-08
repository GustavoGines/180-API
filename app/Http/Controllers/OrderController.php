<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Events\OrderDeleted;
use App\Events\OrderUpdated;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\StoreBotOrderRequest;
use App\Http\Requests\UpdateBotOrderRequest;
use App\Services\BotOrderService;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderImageService;
use App\Services\OrderPriceCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function __construct(
        private OrderPriceCalculator $priceCalculator,
        private OrderImageService $imageService
    ) {}

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
        $validated = $request->validated();
        $files = $request->file('files') ?? [];

        // 1. Procesar imágenes (Placeholders)
        if (isset($validated['items']) && is_array($validated['items'])) {
            $validated['items'] = $this->imageService->processPlaceholders($validated['items'], $files);
        }

        $order = DB::transaction(function () use ($validated) {
            // 2. Calcular totales
            $itemsTotal = $this->priceCalculator->calculateItemsTotal($validated['items']);
            $deliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            $calculatedGrandTotal = $this->priceCalculator->calculateGrandTotal($itemsTotal, $deliveryCost);

            // 3. Preparar datos de la orden
            $orderData = Arr::except($validated, ['items', 'deposit']);
            $orderData['total'] = $calculatedGrandTotal;
            $orderData['status'] = $validated['status'] ?? 'confirmed';
            $orderData['is_paid'] = $validated['is_paid'] ?? false;

            // Calcular depósito
            $originalDeposit = (float) ($validated['deposit'] ?? 0);
            $orderData['deposit'] = $this->priceCalculator->calculateValidDeposit($originalDeposit, $calculatedGrandTotal);

            // 4. Crear orden
            $order = Order::create($orderData);

            // 5. Crear items
            if (! empty($validated['items'])) {
                $this->createOrderItems($order, $validated['items']);
            }

            return $order;
        });

        // 6. Disparar evento (fuera de transacción para evitar race conditions con listeners async si fallara commit)
        // Nota: Si el listener es async (queue), es seguro.
        OrderCreated::dispatch($order);

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
            abort(403, __('messages.unauthorized_action'));
        }

        $validated = $request->validated();
        $files = $request->file('files') ?? [];

        // Obtener URLs antiguas ANTES de procesar los nuevos items
        $order->load('items');
        $oldPhotoUrls = $this->imageService->getPhotoUrls($order->items);

        // Procesar nuevos items (Placeholders)
        if (isset($validated['items']) && is_array($validated['items'])) {
            $validated['items'] = $this->imageService->processPlaceholders($validated['items'], $files);
        }

        $updatedOrder = DB::transaction(function () use ($validated, $order, $oldPhotoUrls) {

            // 1. Calcular Nuevos Totales
            $newItemsTotal = 0.0;
            if (isset($validated['items']) && is_array($validated['items'])) {
                $newItemsTotal = $this->priceCalculator->calculateItemsTotal($validated['items']);
            }
            $newDeliveryCost = (float) ($validated['delivery_cost'] ?? 0);
            $newGrandTotal = $this->priceCalculator->calculateGrandTotal($newItemsTotal, $newDeliveryCost);

            $orderData = Arr::except($validated, ['items', 'deposit']);
            $newDeposit = (float) ($validated['deposit'] ?? 0);

            $orderData['total'] = $newGrandTotal;
            $orderData['deposit'] = $this->priceCalculator->calculateValidDeposit($newDeposit, $newGrandTotal);

            // 2. Actualizar Orden
            $order->update($orderData);

            // 3. Reemplazar Items
            $order->items()->delete();
            if (isset($validated['items']) && is_array($validated['items'])) {
                $this->createOrderItems($order, $validated['items']);
            }

            // 4. Gestionar borrado de imágenes huérfanas
            // Obtenemos las nuevas URLs ya guardados los items (o desde $validated)
            $newPhotoUrls = $this->imageService->getPhotoUrls($validated['items'] ?? []);
            $this->imageService->deleteOrphanedPhotos($oldPhotoUrls, $newPhotoUrls, $order->id);

            return $order;
        });

        OrderUpdated::dispatch($updatedOrder);

        return new OrderResource($updatedOrder->load(['client', 'items']));
    }

    public function updateStatus(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, __('messages.unauthorized_action'));
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required_without:is_fully_paid', 'string', 'in:pending,confirmed,ready,delivered,canceled'],
            'is_paid' => ['sometimes', 'boolean'],
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
            OrderUpdated::dispatch($order);
        }

        return new OrderResource($order->fresh(['client', 'items']));
    }

    public function markAsPaid(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, __('messages.unauthorized_action'));
        }

        if (! is_numeric($order->total) || $order->total <= 0 || $order->deposit >= $order->total) {
            return response()->json([
                'message' => __('messages.order_already_paid_or_invalid'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order->deposit = $order->total;
        $order->is_paid = true;
        $order->save();

        OrderUpdated::dispatch($order);

        return new OrderResource($order->fresh(['client', 'items']));
    }

    public function markAsUnpaid(Request $request, Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, __('messages.unauthorized_action'));
        }

        $order->is_paid = false;

        if ($order->deposit >= $order->total) {
            $order->deposit = 0;
        }

        $order->save();

        OrderUpdated::dispatch($order);

        return response()->json($order->fresh(['client', 'items']));
    }

    public function destroy(Order $order)
    {
        if (! Gate::allows('manage-orders')) {
            abort(403, __('messages.unauthorized_action'));
        }

        // Capturamos datos necesarios para el evento ANTES de borrar
        $orderId = $order->id;
        $googleEventId = $order->google_event_id;

        DB::transaction(function () use ($order) {
            // 1. Borrar imágenes de R2
            $this->imageService->deleteAllPhotosForOrder($order);

            // 2. Borrar orden (cascade delete a items debería manejarse en BD o Modelo,
            // pero si no, items()->delete() explícito)
            $order->items()->delete(); // Por consistencia explícita
            $order->delete();
        });

        // 3. Disparar evento de borrado
        OrderDeleted::dispatch($orderId, $googleEventId);

        return response()->noContent();
    }

    public function checkAvailability(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i',
        ]);

        $dateStr = $request->query('date');
        $timeStr = $request->query('time', '12:00'); // Hora por defecto si no envían

        $requestedDate = \Carbon\Carbon::parse($dateStr);
        $requestedDateTime = \Carbon\Carbon::parse("{$dateStr} {$timeStr}");
        $now = \Carbon\Carbon::now();

        // Regla 1: Días de Descanso (Martes cerrado)
        if ($requestedDate->isTuesday()) {
            return response()->json([
                'available' => false,
                'reason' => 'closed',
                'message' => 'Los martes estamos cerrados.',
                'express_review_needed' => false,
            ]);
        }

        // Regla 2: Cupo Máximo Diario
        // Buscamos si hay un cupo especial para esta fecha, si no usamos el default
        $dailyCapacity = config("shop.special_capacities.{$dateStr}", config('shop.default_daily_capacity', 10));

        // Contamos cuántos pedidos confirmados/listos hay para ese día
        $ordersCount = Order::whereDate('event_date', $dateStr)
            ->whereIn('status', ['draft', 'confirmed', 'ready']) // Consideramos estos estados como ocupando cupo
            ->count();

        if ($ordersCount >= $dailyCapacity) {
            return response()->json([
                'available' => false,
                'reason' => 'full_capacity',
                'message' => 'Cupo lleno para este día.',
                'express_review_needed' => false,
            ]);
        }

        // Regla 3: Anticipación Mínima (< 24 horas)
        $hoursDifference = $now->diffInHours($requestedDateTime, false);

        // Si el pedido es para el pasado, lo rechazamos por obvias razones
        if ($hoursDifference < 0 && ! $requestedDate->isToday()) {
            return response()->json([
                'available' => false,
                'reason' => 'past_date',
                'message' => 'La fecha solicitada ya pasó.',
                'express_review_needed' => false,
            ]);
        }

        // Si falta menos de 24 horas (pero es a futuro o es para hoy)
        if ($requestedDateTime->copy()->subHours(24)->isPast()) {
            return response()->json([
                'available' => true,
                'reason' => 'express',
                'message' => 'El pedido es para dentro de menos de 24 horas, requiere revisión manual.',
                'express_review_needed' => true,
            ]);
        }

        // Si pasa todas las validaciones
        return response()->json([
            'available' => true,
            'reason' => 'ok',
            'message' => 'Fecha y cupo disponibles.',
            'express_review_needed' => false,
        ]);
    }

    /**
     * Helper para transformar y crear items.
     */
    private function createOrderItems(Order $order, array $itemsDataRaw)
    {
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
        }, $itemsDataRaw);
        $order->items()->createMany($itemsData);
    }

    /**
     * Endpoint especial para crear un pedido desde el bot (IA).
     */
    public function storeFromBot(StoreBotOrderRequest $request, BotOrderService $botService)
    {
        $validated = $request->validated();
        
        $order = DB::transaction(function () use ($validated, $botService) {
            // 1. Traducir ítems del bot al formato estándar
            $translatedItems = $botService->translateBotItems($validated['bot_items']);

            // 2. Calcular totales (usando el formato traducido)
            $itemsTotal = $this->priceCalculator->calculateItemsTotal($translatedItems);
            $deliveryCost = 0.0; // Asumimos 0 si el bot no lo maneja, o se podría agregar
            $calculatedGrandTotal = $this->priceCalculator->calculateGrandTotal($itemsTotal, $deliveryCost);

            // 3. Preparar datos de la orden
            $orderData = Arr::except($validated, ['bot_items']);
            $orderData['total'] = $calculatedGrandTotal;
            $orderData['deposit'] = 0.0; // Bot orders suelen entrar con seña 0 inicialmente
            $orderData['is_paid'] = false;
            
            // 4. Crear orden
            $order = Order::create($orderData);

            // 5. Crear items
            $this->createOrderItems($order, $translatedItems);

            return $order;
        });

        OrderCreated::dispatch($order);

        return new OrderResource($order->load(['client', 'items']));
    }

    /**
     * Endpoint especial para actualizar un pedido desde el bot (IA).
     */
    public function updateFromBot(UpdateBotOrderRequest $request, Order $order, BotOrderService $botService)
    {
        $validated = $request->validated();

        $updatedOrder = DB::transaction(function () use ($validated, $order, $botService) {
            // 1. Preparar datos de la orden a actualizar
            $orderData = Arr::except($validated, ['bot_items']);

            // 2. Manejar la actualización de items si se envían
            if (isset($validated['bot_items'])) {
                // Traducir ítems del bot al formato estándar
                $translatedItems = $botService->translateBotItems($validated['bot_items']);

                // Calcular nuevos totales base de los ítems
                $itemsTotal = $this->priceCalculator->calculateItemsTotal($translatedItems);
                $deliveryCost = (float)($order->delivery_cost ?? 0); // Mantener el costo de envío actual de la base de datos
                $calculatedGrandTotal = $this->priceCalculator->calculateGrandTotal($itemsTotal, $deliveryCost);

                // Actualizar total y ajustar depósito si supera el nuevo total
                $orderData['total'] = $calculatedGrandTotal;
                $orderData['deposit'] = $this->priceCalculator->calculateValidDeposit((float)$order->deposit, $calculatedGrandTotal);

                // Reemplazar items
                $order->items()->delete();
                $this->createOrderItems($order, $translatedItems);

                // El bot no maneja fotos directamente por ahora, por lo que no hace falta $imageService->deleteOrphanedPhotos
            }

            // 3. Actualizar orden
            if (!empty($orderData)) {
                $order->update($orderData);
            }

            return $order;
        });

        OrderUpdated::dispatch($updatedOrder);

        return new OrderResource($updatedOrder->load(['client', 'items']));
    }
}
