<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Filling extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'price_per_kg' => 'decimal:2',
    ];
}
