<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'event_date', 'start_time', 'end_time', 'status',
        'total', 'deposit', 'delivery_cost', 'notes', 'google_event_id', 'client_address_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
    
    // Un pedido pertenece a una dirección
    public function address()
    {
        return $this->belongsTo(ClientAddress::class, 'client_address_id');
    }
    
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
