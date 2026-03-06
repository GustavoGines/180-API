<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderDeleted
{
    use Dispatchable, SerializesModels;

    // Pasamos el ID y opcionalmente el google_event_id porque el modelo ya fue eliminado de BD 
    // y SerializesModels podría fallar si intenta recargarlo, AUNQUE si la transacción commitó, ya no existe.
    // PERO: Si usamos SerializesModels, Laravel serializa el identificador. Al deserializar, fallará si no existe.
    // ESTRATEGIA: Pasar datos primitivos o un DTO, O no usar SerializesModels si pasamos el objeto eliminado (peligroso en queues).
    // MEJOR OPCIÓN PARA DELETE ASÍNCRONO: Pasar los datos necesarios explicitamente (google_event_id).
    
    public function __construct(public int $orderId, public ?string $googleEventId)
    {
    }
}
