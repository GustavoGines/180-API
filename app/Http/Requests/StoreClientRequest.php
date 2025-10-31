<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule; // 👈 1. Importar la clase Rule

class StoreClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 2. Obtenemos el cliente de la ruta (será null en 'store', 
        //    y será el objeto Client en 'update')
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:120'],
            
            'phone' => [
                'nullable', 
                'string', 
                'max:40',
                // 3. Regla unique:
                // "Debe ser único en la tabla 'clients', 
                //  PERO ignora el ID del cliente que estamos actualizando"
                Rule::unique('clients')->ignore($client?->id),
            ],
            
            'email' => [
                'nullable', 
                'email',
                Rule::unique('clients')->ignore($client?->id),
            ],

            'ig_handle' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}