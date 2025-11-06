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

class SendTodayOrderNotifications extends Command
{
    protected $signature = 'app:send-today-notifications';
    protected $description = 'Envía un recordatorio matutino para todos los pedidos del día actual.';

    protected $messaging;

    public function __construct(Messaging $messaging)
    {
        parent::__construct();
        $this->messaging = $messaging;
    }

    public function handle()
    {
        // 1. Obtener la fecha de HOY (usando la zona horaria configurada en app.php)
        $today = now()->toDateString();
        $this->info("Buscando todos los pedidos para HOY ($today)...");

        // 2. Buscar todos los pedidos confirmados para hoy
        $orders = Order::whereDate('event_date', $today)
            ->where('status', 'confirmed')
            ->with('client')
            ->orderBy('start_time')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No se encontraron pedidos para hoy.');
            return 0;
        }

        $this->info("Se encontraron {$orders->count()} pedidos para notificar.");

        // 3. Obtener tokens de admin/staff
        $adminAndStaffTokens = Device::whereHas('user', function ($query) {
            $query->whereIn('role', ['admin', 'staff']);
        })->pluck('fcm_token')->filter()->unique();

        if ($adminAndStaffTokens->isEmpty()) {
            $this->info('No hay dispositivos de admin/staff registrados para notificar.');
            return 0;
        }

        $this->info("Enviando a {$adminAndStaffTokens->count()} dispositivo(s) de admin/staff.");

        // 4. Iterar y enviar notificaciones
        foreach ($orders as $order) {
            if (!$order->client) {
                Log::warning("Pedido #{$order->id} (de hoy) sin cliente asignado.");
                continue;
            }

            $title = '¡Pedidos para HOY!';
            $body = "Hoy tenés el pedido de {$order->client->name} a las {$order->start_time->format('H:i')}hs.";

            foreach ($adminAndStaffTokens as $fcmToken) {
                Log::info("Notificando (HOY) token {$fcmToken} para Pedido #{$order->id}");
                $this->sendNotification($fcmToken, $title, $body, $order->id);
            }
        }

        $this->info('✅ Recordatorios de HOY enviados.');
        return 0;
    }

    private function sendNotification($fcmToken, $title, $body, int $orderId)
    {
        try {
            $notification = Notification::create($title, $body);
            
            $message = CloudMessage::withTarget('token', $fcmToken)
                ->withNotification($notification)
                // ✅ CAMBIO: Adjuntar el payload de datos
                ->withData([
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK', // ID estándar para Android
                    'type' => 'order_detail', // Un tipo custom para que tu app sepa qué hacer
                    'orderId' => (string)$orderId, // ¡El ID del pedido! (debe ser string)
                ]);
                
            $this->messaging->send($message);

        } catch (\Kreait\Firebase\Exception\Messaging\InvalidMessage $e) {
            Log::error("Error de FCM (Mensaje Inválido) para token {$fcmToken}: " . $e->getMessage());
        } catch (\Kreait\Firebase\Exception\Messaging\NotFound $e) {
            Log::warning("Token FCM no encontrado, se debería borrar: {$fcmToken}");
            Device::where('fcm_token', $fcmToken)->delete();
        } catch (\Exception $e) {
            Log::error("Error genérico al enviar FCM a token {$fcmToken}: " . $e->getMessage());
        }
    }
}
