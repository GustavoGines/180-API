<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'label',
        'address_line_1',
        'latitude',
        'longitude',
        'google_maps_url',
        'notes',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * 👇 --- AÑADE ESTE MÉTODO --- 👇
     *
     * Relación: La dirección puede estar en muchos pedidos.
     */
    public function orders()
    {
        // Asume que la tabla 'orders' tiene la columna 'client_address_id'
        return $this->hasMany(Order::class, 'client_address_id');
    }
}
