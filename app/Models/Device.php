<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['user_id', 'fcm_token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
