<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Device;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class SendTomorrowOrderNotifications extends Command
{
    protected $signature = 'app:send-tomorrow-notifications';
    protected $description = 'Envía notificaciones 24hs antes de los pedidos confirmados.';

    protected $messaging;

    public function __construct(Messaging $messaging)
    {
        parent::__construct();
        $this->messaging = $messaging;
    }

    public function handle()
    {
        // Redondea la hora actual HACIA ABAJO al múltiplo de 5 más cercano
        $baseTime = now()->floorMinutes(5);

        // Define el rango de 5 minutos, 24 horas en el futuro desde esa hora base
        $from = $baseTime->copy()->addHours(24);
        $to = $from->copy()->addMinutes(5);

        $this->info("Buscando pedidos en el rango FIJO de 24h: {$from->format('Y-m-d H:i')} a {$to->format('Y-m-d H:i')}...");

        $orders = Order::where('event_date', $from->toDateString())
            ->whereTime('start_time', '>=', $from->toTimeString())
            ->whereTime('start_time', '<', $to->toTimeString())
            ->where('status', 'confirmed')
            ->with('client')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No se encontraron pedidos para notificar en este rango.');
            return 0;
        }

        $this->info("Se encontraron {$orders->count()} pedidos para notificar.");

        // Obtener tokens de dispositivos de usuarios admin/staff
        $adminAndStaffTokens = Device::whereHas('user', function ($query) {
            $query->whereIn('role', ['admin', 'staff']);
        })
            ->pluck('fcm_token')
            ->filter()
            ->unique();

        if ($adminAndStaffTokens->isEmpty()) {
            $this->info('No hay dispositivos de admin/staff registrados para notificar.');
            return 0;
        }

        $this->info("Enviando a {$adminAndStaffTokens->count()} dispositivo(s) de admin/staff.");

        foreach ($orders as $order) {
            if (!$order->client) {
                Log::warning("Pedido #{$order->id} sin cliente asignado.");
                continue;
            }

            $title = '¡Pedido para Mañana!';
            $body = "Recuerda el pedido de {$order->client->name} para mañana a las {$order->start_time->format('H:i')}hs.";

            foreach ($adminAndStaffTokens as $fcmToken) {
                Log::info("Notificando token {$fcmToken} para Pedido #{$order->id}");
                $this->sendNotification($fcmToken, $title, $body);
            }
        }

        $this->info('✅ Notificaciones enviadas.');
        return 0;
    }

    /**
     * Envía una notificación individual a un token FCM
     */
    private function sendNotification($fcmToken, $title, $body)
    {
        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification($notification);

            $this->messaging->send($message);
        } catch (\Kreait\Firebase\Exception\Messaging\InvalidMessage $e) {
            Log::error("Error de FCM (Mensaje Inválido) para token {$fcmToken}: " . $e->getMessage());
        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            Log::warning("Token FCM no encontrado, se debería borrar: {$fcmToken}");
        } catch (\Exception $e) {
            Log::error("Error genérico al enviar FCM a token {$fcmToken}: " . $e->getMessage());
        }
    }
}
