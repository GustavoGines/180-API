<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule; // 👈 1. IMPORTAR LA CLASE 'Rule'

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
            'client_id' => ['required', 'exists:clients,id'],
            'client_address_id' => [
                'required', // Ahora es requerido
                'integer',
                // Regla de existencia: 
                // 1. Debe existir en la tabla 'client_addresses'
                // 2. Y debe pertenecer al 'client_id' que también se está enviando en este request.
                Rule::exists('client_addresses', 'id')->where(function ($query) {
                    return $query->where('client_id', $this->input('client_id'));
                }),
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', 'string', 'in:confirmed,ready,delivered,canceled'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:order_items,id'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.qty' => ['required', 'integer', 'min:1'],

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
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
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
                    $v->errors()->add('end_time', 'La hora de fin debe ser posterior a la hora de inicio.');
                }
            }

            // 2) Deposit validation vs calculated total (UPDATED)
            $items = $this->input('items', []);
            $deliveryCost = (float) ($this->input('delivery_cost') ?? 0);

            if (is_array($items) && ! empty($items)) {
                $calculatedItemsTotal = 0.0;

                foreach ($items as $key => $item) {
                    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 0;
                    $basePrice = isset($item['base_price']) && is_numeric($item['base_price']) ? (float) $item['base_price'] : -1.0; 
                    $adjustments = isset($item['adjustments']) && is_numeric($item['adjustments']) ? (float) $item['adjustments'] : 0.0; 

                    if ($qty <= 0 || $basePrice < 0) {
                         $v->errors()->add("items.$key", 'El ítem tiene cantidad o precio base inválido.');
                         continue; 
                    }

                    $finalUnitPrice = $basePrice + $adjustments;

                    if ($finalUnitPrice < 0) {
                         $v->errors()->add("items.$key", 'El precio final del ítem (base + ajuste) no puede ser negativo.');
                         continue;
                    }

                    $calculatedItemsTotal += $qty * $finalUnitPrice;
                }

                 if (! $v->errors()->has('items.*')) {
                    $calculatedGrandTotal = $calculatedItemsTotal + $deliveryCost;
                    $deposit = (float) ($this->input('deposit') ?? 0);
                    $epsilon = 0.01;

                    if ($deposit > ($calculatedGrandTotal + $epsilon)) {
                        $v->errors()->add(
                            'deposit',
                            'El depósito (\$'.number_format($deposit, 0, ',', '.').') no puede ser mayor al total del pedido (\$'.number_format($calculatedGrandTotal, 0, ',', '.').').'
                        );
                    }
                }

            } elseif ($this->input('deposit') > 0) {
                 $v->errors()->add('deposit', 'No se puede registrar un depósito si no hay productos en el pedido.');
            }
        });
    }

     /**
     * Prepare the data for validation.
     * (Esta función no necesita cambios)
     * @return void
     */
    protected function prepareForValidation()
    {
        if ($this->has('items') && is_array($this->items)) {
            $cleanedItems = [];
            foreach ($this->items as $item) {
                // limpiar precios
                foreach (['base_price','adjustments'] as $f) {
                    if (isset($item[$f])) {
                        $item[$f] = str_replace(',', '.', preg_replace('/[^\d\-,.]/', '', (string)$item[$f]));
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

