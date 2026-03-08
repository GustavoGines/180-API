<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBotOrderRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'client_id' => 'sometimes|exists:clients,id',
            'event_date' => 'sometimes|date_format:Y-m-d',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'status' => ['sometimes', 'string', Rule::in(['draft', 'pending', 'confirmed', 'ready', 'delivered', 'canceled'])],
            
            // Si envían bot_items en el PUT, se validará completo igual que al crear
            'bot_items' => 'sometimes|array|min:1',
            'bot_items.*.category_name' => 'required_with:bot_items|string',
            'bot_items.*.product_name' => 'required_with:bot_items|string',
            'bot_items.*.qty' => 'required_with:bot_items|integer|min:1',
            'bot_items.*.base_price' => 'required_with:bot_items|numeric|min:0',
            'bot_items.*.adjustments' => 'nullable|numeric',
            'bot_items.*.weight_kg' => 'nullable|numeric|min:0.5',
            
            'bot_items.*.fillings' => 'nullable|array',
            'bot_items.*.fillings.*' => 'string|max:255',
            
            'bot_items.*.extras' => 'nullable|array',
            'bot_items.*.extras.*' => 'string|max:255',
            
            'bot_items.*.customization_notes' => 'nullable|string',
        ];
    }
}
