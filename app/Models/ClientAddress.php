<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientAddress extends Model
{
    use HasFactory;

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
}
