<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator; // 👈 1. IMPORTAR LA CLASE 'Rule'

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // (Sin cambios)
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
            'client_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (! \App\Models\Client::withTrashed()->where('id', $value)->exists()) {
                        $fail('El cliente seleccionado es inválido o no existe.');
                    }
                },
            ],
            // CÓDIGO LARAVEL CORREGIDO
            'client_address_id' => [
                // La dirección es opcional por defecto.
                'nullable',
                'integer',

                // PERO: Si el campo 'delivery_cost' es mayor que 0, entonces SÍ es requerido.
                'required_if:delivery_cost,>0',

                // Regla de existencia (se mantiene)
                Rule::exists('client_addresses', 'id')->where(function ($query) {
                    return $query->where('client_id', $this->input('client_id'));
                }),
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', 'string', 'in:pending,confirmed,ready,delivered,canceled'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_paid' => ['nullable', 'boolean'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:order_items,id'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.qty' => ['required', 'numeric', 'min:0.1'],

            'items.*.base_price' => ['required', 'numeric', 'min:0'],
            'items.*.adjustments' => ['nullable', 'numeric'],
            'items.*.customization_notes' => ['nullable', 'string'],

            'items.*.customization_json' => ['nullable', 'array'],
            'items.*.customization_json.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'items.*.customization_json.selected_fillings' => ['nullable', 'array'],
            'items.*.customization_json.calculated_final_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.customization_json.photo_urls' => ['nullable', 'array'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        // (Esta función no necesita cambios, tu lógica de validación de
        // tiempo y depósito es independiente de la dirección)
        $validator->after(function (Validator $v) {
            // 1) End time > Start time validation (No change)
            $start = $this->input('start_time');
            $end = $this->input('end_time');
            if ($start && $end) {
                $startTime = \DateTime::createFromFormat('H:i', $start);
                $endTime = \DateTime::createFromFormat('H:i', $end);
                if ($startTime && $endTime && $endTime <= $startTime) {
                    $v->errors()->add('end_time', __('messages.validation.end_time_after_start'));
                }
            }

            // 2) Deposit validation vs calculated total using Service
            $items = $this->input('items', []);
            $deliveryCost = (float) ($this->input('delivery_cost') ?? 0);
            $deposit = (float) ($this->input('deposit') ?? 0);

            $calculator = app(\App\Services\OrderCalculatorService::class);
            $calculator->validateOrder($v, $items, $deliveryCost, $deposit);
        });
    }

    /**
     * Prepare the data for validation.
     * (Esta función no necesita cambios)
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // 1. Decodificar 'order_payload' si viene en la petición (Multipart)
        if ($this->has('order_payload')) {
            $payload = json_decode($this->input('order_payload'), true);
            if (is_array($payload)) {
                $this->merge($payload);
            }
        }

        // 2. Limpieza de items (precios y campos calculados)
        if ($this->has('items') && is_array($this->items)) {
            $cleanedItems = [];
            foreach ($this->items as $item) {
                // limpiar precios (2.500,00 -> 2500.00)
                foreach (['base_price', 'adjustments'] as $f) {
                    if (isset($item[$f])) {
                        $item[$f] = str_replace(',', '.', preg_replace('/[^\d\-,.]/', '', (string) $item[$f]));
                    }
                }
                // eliminar campos calculados del json si llegan
                if (isset($item['customization_json']) && is_array($item['customization_json'])) {
                    unset($item['customization_json']['calculated_final_unit_price']);
                    unset($item['customization_json']['calculated_base_price']);
                }
                $cleanedItems[] = $item;
            }
            $this->merge(['items' => $cleanedItems]);
        }
    }
}
