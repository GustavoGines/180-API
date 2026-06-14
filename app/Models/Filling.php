<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Filling extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    protected $casts = [
        'is_free' => 'boolean',
        'is_active' => 'boolean',
        'price_per_kg' => 'decimal:2',
    ];
}
