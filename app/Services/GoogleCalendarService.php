<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;

class GoogleCalendarService
{
    private Calendar $calendar;
    private string $calendarId;
    private string $timezone = 'America/Argentina/Buenos_Aires';

    public function __construct()
    {
        // Toma de config/app.php; si no, usa Buenos_Aires.
        $this->timezone   = config('app.timezone', $this->timezone);
        $this->calendarId = (string) env('GOOGLE_CALENDAR_ID', '');

        if ($this->calendarId === '') {
            throw new \RuntimeException('Falta GOOGLE_CALENDAR_ID en .env');
        }

        $client = new GoogleClient();

        // Credenciales: BASE64 → JSON inline → PATH
        $jsonInline = env('GOOGLE_APP_CREDENTIALS_JSON');
        $jsonBase64 = env('GOOGLE_APP_CREDENTIALS_BASE64');
        $jsonPath   = env('GOOGLE_APP_CREDENTIALS_PATH');

        if (!empty($jsonBase64)) {
            $decoded = json_decode(base64_decode($jsonBase64, true) ?: '', true);
            if (!$decoded) {
                throw new \RuntimeException('GOOGLE_APP_CREDENTIALS_BASE64 inválido o mal formateado.');
            }
            $client->setAuthConfig($decoded);
        } elseif (!empty($jsonInline)) {
            $decoded = json_decode($jsonInline, true);
            if (!$decoded) {
                throw new \RuntimeException('GOOGLE_APP_CREDENTIALS_JSON inválido.');
            }
            $client->setAuthConfig($decoded);
        } elseif (!empty($jsonPath)) {
            if (!is_readable($jsonPath)) {
                throw new \RuntimeException("No se puede leer GOOGLE_APP_CREDENTIALS_PATH: {$jsonPath}");
            }
            $client->setAuthConfig($jsonPath);
        } else {
            throw new \RuntimeException('Faltan credenciales: configure GOOGLE_APP_CREDENTIALS_BASE64 o JSON o PATH.');
        }

        $client->setScopes([Calendar::CALENDAR]);
        $this->calendar = new Calendar($client);
    }

    public function createFromOrder(Order $order): string
    {
        $event = $this->buildEventFromOrder($order);
        $created = $this->calendar->events->insert($this->calendarId, $event, ['sendUpdates' => 'none']);
        return $created->id;
    }

    public function updateFromOrder(Order $order): void
    {
        $event = $this->buildEventFromOrder($order);

        if (!empty($order->google_event_id)) {
            $this->calendar->events->update($this->calendarId, $order->google_event_id, $event, ['sendUpdates' => 'none']);
        } else {
            $order->google_event_id = $this->createFromOrder($order);
            $order->save();
        }
    }

    public function deleteEvent(string $googleEventId): void
    {
        $this->calendar->events->delete($this->calendarId, $googleEventId);
    }

    private function buildEventFromOrder(Order $order): Event
    {
        // Garantiza cliente cargado
        $order->loadMissing('client');
        $client = $order->client;

        // Toma horas (si faltan, defaults amigables)
        $startH = $order->start_time ? (is_string($order->start_time) ? $order->start_time : $order->start_time->format('H:i')) : '10:00';
        $endH   = $order->end_time   ? (is_string($order->end_time)   ? $order->end_time   : $order->end_time->format('H:i'))   : '10:30';

        // Combina fecha + hora en la TZ de AR evitando offsets
        $dateStr = $order->event_date instanceof \Carbon\CarbonInterface
            ? $order->event_date->format('Y-m-d')
            : (string) $order->event_date;

        $start = CarbonImmutable::parse("{$dateStr} {$startH}", $this->timezone);
        $end   = CarbonImmutable::parse("{$dateStr} {$endH}",   $this->timezone);

        $description = trim(
            "Cliente: " . ($client->name ?? '-') . "\n" .
            "Tel: " . ($client->phone ?? '-') . "\n" .
            "Dirección: " . ($client->address ?? '-') . "\n" .
            "Notas: " . ($order->notes ?? '-') . "\n" .
            "Total: $ " . number_format((float)$order->total, 2, ',', '.')
        );

        // Importante: enviar dateTime SIN 'Z' y especificar timeZone explícitamente
        return new Event([
            'summary'     => "Pedido – " . ($client->name ?? 'Cliente'),
            'description' => $description,
            'start'       => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => $this->timezone,
            ],
            'end'         => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => $this->timezone,
            ],
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 1440], // 24 h
                    ['method' => 'popup', 'minutes' => 180],  // 3  h
                ],
            ],
        ]);
    }
}
