<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClientAddressRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Asumimos que si el usuario está autenticado, puede modificar.
        // Puedes añadir lógica de roles aquí si es necesario.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 'sometimes' significa que solo valida si está presente (útil para PUT/PATCH)
        // 'required_without_all' es clave: debe proveer al menos una dirección, URL o coordenadas.
        return [
            'label' => ['sometimes', 'string', 'max:100'],
            'address_line_1' => [
                'nullable',
                'string',
                'max:255',
                'required_without_all:latitude,google_maps_url'
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
                'required_with:longitude' // Si envías lat, debes enviar lng
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
                'required_with:latitude' // Si envías lng, debes enviar lat
            ],
            'google_maps_url' => ['nullable', 'url', 'max:2048'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
