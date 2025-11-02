<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Mantener en true si cualquier usuario autenticado puede crear/editar pedidos
        // O añadir lógica de autorización si es necesario (ej. Auth::check())
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
                // 1. Debe existir en la tabla 'client_addresses' (columna 'id')
                // 2. Y ADEMÁS, el 'client_id' de esa dirección debe coincidir 
                //    con el 'client_id' que también se está enviando en este request.
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
            'items.*.id' => ['nullable', 'integer', 'exists:order_items,id'], // Allow ID for updates
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.qty' => ['required', 'integer', 'min:1'],

            // --- VALIDATION FOR NEW PRICE FIELDS ---
            'items.*.base_price' => ['required', 'numeric', 'min:0'],
            'items.*.adjustments' => ['nullable', 'numeric'], // Allows negative
            'items.*.customization_notes' => ['nullable', 'string'],
            // --- END VALIDATION ---

            'items.*.customization_json' => ['nullable', 'array'],
            // Optional: More specific validation for customization_json
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
                    // Validate required numeric fields for calculation
                    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 0;
                    // 👇 Use base_price and adjustments for calculation
                    $basePrice = isset($item['base_price']) && is_numeric($item['base_price']) ? (float) $item['base_price'] : -1.0; // Use -1 to detect missing/invalid
                    $adjustments = isset($item['adjustments']) && is_numeric($item['adjustments']) ? (float) $item['adjustments'] : 0.0; // Default adjustments to 0 if missing/invalid

                    // Check if base price is valid
                    if ($qty <= 0 || $basePrice < 0) {
                         $v->errors()->add("items.$key", 'El ítem tiene cantidad o precio base inválido.');
                         // Don't 'return' here, let it check all items first
                         continue; // Skip to next item
                    }

                    // Calculate final unit price for this item
                    $finalUnitPrice = $basePrice + $adjustments;

                    // Ensure final unit price is not negative (unless explicitly allowed?)
                    if ($finalUnitPrice < 0) {
                         $v->errors()->add("items.$key", 'El precio final del ítem (base + ajuste) no puede ser negativo.');
                         continue;
                    }

                    $calculatedItemsTotal += $qty * $finalUnitPrice; // Sum using final price
                }

                 // Only proceed with deposit validation if there were no item errors above
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
     * Convierte fechas y horas al formato esperado si es necesario.
     *
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
