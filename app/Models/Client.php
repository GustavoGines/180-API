<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Client extends Model
{

    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'email', 'ig_handle', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function addresses()
    {
        return $this->hasMany(ClientAddress::class);
    }

    protected $dates = ['deleted_at'];

    /**
     * Define qué campos extra debe añadir el modelo al convertir a JSON.
     */
    protected $appends = [
        'whatsapp_url'
    ];

    /**
     * Crea un atributo "whatsapp_url" que no existe en la BD.
     */
    public function getWhatsappUrlAttribute(): ?string
    {
        if (empty($this->phone)) {
            return null;
        }

        // 1. Limpia el número
        $sanitizedPhone = preg_replace('/[^0-9]/', '', $this->phone);
        
        // 2. Añade el prefijo 549
        $whatsappNumber = '549' . $sanitizedPhone;

        // 3. Retorna la URL completa
        return 'https://wa.me/' . $whatsappNumber;
    }
}
