<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log; // Importante para loguear
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
        $now = now();
        $from = $now->copy()->addHours(24); // Rango de inicio: exactamente 24hs desde ahora
        $to = $from->copy()->addMinutes(5); // Rango de fin: 24hs + 5 mins desde ahora
    
        $this->info("Buscando pedidos entre {$from->format('Y-m-d H:i')} y {$to->format('Y-m-d H:i')}...");
    
        // ✅ CONSULTA CORREGIDA
        $orders = Order::where('event_date', $from->toDateString()) // 1. Que el DÍA sea mañana
                       ->whereTime('start_time', '>=', $from->toTimeString()) // 2. Que la HORA sea >=
                       ->whereTime('start_time', '<', $to->toTimeString()) // 3. Que la HORA sea <
                       ->where('status', 'confirmed')
                       ->with('client.devices')
                       ->get();
    
        if ($orders->isEmpty()) {
            $this->info('No se encontraron pedidos para notificar en este rango.');
            return 0;
        }
    
        $this->info("Se encontraron {$orders->count()} pedidos para notificar.");
    
        foreach ($orders as $order) {
            if (!$order->client || $order->client->devices->isEmpty()) {
                Log::warning("Pedido #{$order->id} sin cliente o sin dispositivos registrados.");
                continue;
            }
    
            // 3. Preparar el mensaje
            $title = '¡Pedido para Mañana!';
            // Corregimos el mensaje para que sea más claro
            $body = "Recuerda el pedido de {$order->client->name} para mañana a las " . $order->start_time->format('H:i') . "hs.";
    
            foreach ($order->client->devices as $device) {
                if ($device->fcm_token) {
                    $this->info("Enviando a token {$device->fcm_token} para Pedido #{$order->id}");
                    
                    // 🚀 AQUÍ IMPLEMENTAREMOS LA LÓGICA DE ENVÍO
                    $this->sendNotification($device->fcm_token, $title, $body);
                }
            }
        }
    
        $this->info('✅ Notificaciones enviadas.');
        return 0;
    }


    /**
     * Helper para enviar la notificación (Implementación pendiente)
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
            // El token es viejo o inválido, deberíamos borrarlo de la BD
            Log::warning("Token FCM no encontrado, se debería borrar: {$fcmToken}");
            // Opcional: Borrar el dispositivo
            // Device::where('fcm_token', $fcmToken)->delete();
        } catch (\Exception $e) {
            Log::error("Error genérico al enviar FCM a token {$fcmToken}: " . $e->getMessage());
        }
    }
    
}