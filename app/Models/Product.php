<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_half_dozen' => 'boolean',
        'is_combo' => 'boolean',
        'base_price' => 'decimal:2',
        'half_dozen_price' => 'decimal:2',
        'multiplier_adjustment_per_kg' => 'decimal:2',
        'available_from' => 'date',
        'available_until' => 'date',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
