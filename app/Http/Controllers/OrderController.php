<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
        $status = $request->query('status');    // draft|confirmed|delivered|canceled

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

        /** @var Order $order */
        $order = null;

        DB::transaction(function () use (&$order, $validated) {
            $order = Order::create(Arr::except($validated, ['items']));
            foreach ($validated['items'] as $itemPayload) {
                $order->items()->create($itemPayload);
            }

            // Crear evento en Google Calendar y guardar el ID
            $googleEventId = $this->googleCalendarService->createFromOrder($order->fresh(['client', 'items']));
            $order->google_event_id = $googleEventId;
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
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $order) {
            // 1) Actualizar cabecera SIN items ni total
            $order->update(Arr::except($validated, ['items', 'total', 'deposit']));

            // 2) Evitar violación del CHECK mientras total queda en 0
            $originalDeposit = $validated['deposit'] ?? $order->deposit;
            $order->deposit = 0;     // o null
            $order->save();

            // 3) Reemplazar ítems
            $order->items()->delete();
            foreach ($validated['items'] as $itemPayload) {
                $order->items()->create($itemPayload);
            }

            // 4) Recalcular total (si tenés método o dejás que lo haga el trigger)
            // $order->recalculateTotals(); // si implementaste este método
            $order->refresh(); // tomar total actualizado por el trigger

            // 5) Ajustar depósito final (nunca mayor al total)
            if ($originalDeposit !== null) {
                $order->deposit = min((float) $originalDeposit, (float) $order->total);
                $order->save();
            }

            // 6) Sincronizar Calendar
            $this->googleCalendarService->updateFromOrder($order->fresh(['client', 'items']));
        });

        return response()->json($order->load(['client', 'items']));
    }

    /**
     * DELETE /api/orders/{order}
     * Elimina el pedido y su evento en Google Calendar (si existe).
     */
    public function destroy(Order $order)
    {
        DB::transaction(function () use ($order) {
            if (! empty($order->google_event_id)) {
                $this->googleCalendarService->deleteEvent($order->google_event_id);
            }
            $order->delete();
        });

        return response()->noContent();
    }

    public function uploadPhoto(Request $request)
    {
        // 1. Validar que se haya enviado un archivo y que sea una imagen
        $request->validate([
            'photo' => 'required|image|max:2048', // Límite de 2MB
        ]);
    
        // 2. Guardar la imagen en storage/app/public/order-photos
        $path = $request->file('photo')->store('order-photos', 'public');
    
        // 3. Devolver la URL pública completa de la imagen
        return response()->json([
            'url' => asset('storage/' . $path)
        ]);
    }
}
