<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'event_date' => $this->event_date,
            'start_time' => $this->start_time?->format('H:i'),
            'end_time' => $this->end_time?->format('H:i'),
            'status' => $this->status,
            'total' => $this->total,
            'deposit' => $this->deposit,
            'delivery_cost' => $this->delivery_cost,
            'is_paid' => $this->is_paid,
            'notes' => $this->notes,
            'client_address_id' => $this->client_address_id,
            'google_event_id' => $this->google_event_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relaciones
            'client' => new ClientResource($this->whenLoaded('client')),
            'client_address' => $this->whenLoaded('clientAddress'), // Si tuvieramos ClientAddressResource lo usaríamos aquí
            'items' => $this->whenLoaded('items'), // Idealmente OrderItemResource
        ];
    }
}
