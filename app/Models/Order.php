<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'event_date', 'start_time', 'end_time', 'status',
        'total', 'deposit', 'delivery_cost', 'notes', 'google_event_id', 'client_address_id',
        'is_paid',
    ];

    protected $casts = [
        'event_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'is_paid' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    // Un pedido pertenece a una dirección
    public function clientAddress()
    {
        return $this->belongsTo(ClientAddress::class, 'client_address_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
