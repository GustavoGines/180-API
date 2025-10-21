<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = ['name', 'phone', 'email', 'ig_handle', 'address', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
