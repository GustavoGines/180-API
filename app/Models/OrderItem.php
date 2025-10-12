<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = ['order_id','name','qty','unit_price','customization_json'];
    protected $casts = ['customization_json' => 'array'];
    public function order(){ return $this->belongsTo(Order::class); }
}
