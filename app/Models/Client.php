<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /**
     * Define qué campos extra debe añadir el modelo al convertir a JSON.
     */
    protected $appends = [
        'whatsapp_url',
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

        // 2. Añade el prefijo 549 solo si no lo tiene ya
        if (str_starts_with($sanitizedPhone, '549')) {
            $whatsappNumber = $sanitizedPhone;
        } else {
            $whatsappNumber = '549'.ltrim($sanitizedPhone, '0'); // También quitamos ceros a la izquierda por si guardaron 0370...
        }

        // 3. Retorna la URL completa
        return 'https://wa.me/'.$whatsappNumber;
    }
}
