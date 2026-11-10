<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Events\OrderDeleted;
use App\Events\OrderUpdated;
use App\Services\GoogleCalendarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SyncOrderToGoogleCalendar implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public function __construct(private GoogleCalendarService $service) {}

    /**
     * Handle OrderCreated event.
     */
    public function handleOrderCreated(OrderCreated $event): void
    {
        try {
            $order = $event->order;
            // Recargamos relaciones necesarias si no están
            $order->loadMissing(['client', 'items']);

            $googleEventId = $this->service->createFromOrder($order);

            // Guardamos el ID del evento de Google en la orden
            // Nota: Al ser async, esto ocurrirá después de que la respuesta HTTP se haya enviado.
            $order->google_event_id = $googleEventId;
            $order->saveQuietly(); // Evitar disparar eventos de 'updated' recursivamente si observamos 'saved'
        } catch (\Throwable $e) {
            Log::error("Error async Google Calendar (Create) Order {$event->order->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle OrderUpdated event.
     */
    public function handleOrderUpdated(OrderUpdated $event): void
    {
        try {
            $order = $event->order;
            $order->loadMissing(['client', 'items']);

            $this->service->updateFromOrder($order);
        } catch (\Throwable $e) {
            Log::error("Error async Google Calendar (Update) Order {$event->order->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle OrderDeleted event.
     */
    public function handleOrderDeleted(OrderDeleted $event): void
    {
        if (empty($event->googleEventId)) {
            return;
        }

        try {
            $this->service->deleteEvent($event->googleEventId);
        } catch (\Throwable $e) {
            Log::error("Error async Google Calendar (Delete) Event {$event->googleEventId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCreated::class => 'handleOrderCreated',
            OrderUpdated::class => 'handleOrderUpdated',
            OrderDeleted::class => 'handleOrderDeleted',
        ];
    }
}
