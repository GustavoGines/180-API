<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBotOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * En este caso cualquier bot con el endpoint (o si luego se agrega auth token) puede.
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
        return [
            'client_id' => 'required|exists:clients,id',
            'event_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => ['required', 'string', Rule::in(['draft', 'pending', 'confirmed', 'ready', 'delivered', 'canceled'])],
            
            'bot_items' => 'required|array|min:1',
            'bot_items.*.category_name' => 'required|string',
            'bot_items.*.product_name' => 'required|string',
            'bot_items.*.qty' => 'required|integer|min:1',
            'bot_items.*.base_price' => 'required|numeric|min:0',
            'bot_items.*.adjustments' => 'nullable|numeric',
            'bot_items.*.weight_kg' => 'nullable|numeric|min:0.5',
            
            // Arrays of strings for names
            'bot_items.*.fillings' => 'nullable|array',
            'bot_items.*.fillings.*' => 'string|max:255',
            
            'bot_items.*.extras' => 'nullable|array',
            'bot_items.*.extras.*' => 'string|max:255',
            
            'bot_items.*.customization_notes' => 'nullable|string',
        ];
    }
}
