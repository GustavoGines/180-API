<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Client extends Model
{

    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'ig_handle', 'address', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    protected $dates = ['deleted_at'];
}
