<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Events\OrderDeleted;
use App\Events\OrderUpdated;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
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
            'status' => ['sometimes', 'required_without:is_fully_paid', 'string', 'in:confirmed,ready,delivered,canceled'],
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
}
