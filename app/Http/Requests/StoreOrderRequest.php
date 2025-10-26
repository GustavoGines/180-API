<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'event_date' => ['required', 'date_format:Y-m-d'], // Asegurar formato YYYY-MM-DD

            // Horarios opcionales (H:i). La validación end > start se hace en withValidator
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],

            'status' => ['nullable', 'string', 'in:confirmed,ready,delivered,canceled'],
            // 'total' => ['nullable', 'numeric', 'min:0'], // <-- ELIMINADO: Ya no se envía, se calcula en el backend
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'], // <-- NUEVO: Costo de envío
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:191'], // Max 191 suele ser más seguro para utf8mb4
            'items.*.qty' => ['required', 'integer', 'min:1'],
            // unit_price ahora es el precio *calculado* por Flutter para ese item (puede ser por kg, por tamaño, etc.)
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            // customization_json es clave ahora, contiene los detalles
            'items.*.customization_json' => ['nullable', 'array'],
            // Podríamos añadir validaciones más específicas para customization_json si fuera necesario
            // ej: 'items.*.customization_json.weight_kg' => ['required_if:items.*.customization_json.product_category,torta', 'numeric', 'min:0']
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
            // 1) Validación de horario: end_time > start_time (sin cambios)
            $start = $this->input('start_time');
            $end = $this->input('end_time');

            if ($start && $end) {
                // Convertir a objetos DateTime para comparación segura
                $startTime = \DateTime::createFromFormat('H:i', $start);
                $endTime = \DateTime::createFromFormat('H:i', $end);
                if ($startTime && $endTime && $endTime <= $startTime) {
                    $v->errors()->add('end_time', 'La hora de fin debe ser posterior a la hora de inicio.');
                }
            }


            // 2) Validación de depósito: no puede ser mayor al NUEVO total (items + delivery_cost)
            $items = $this->input('items', []);
            $deliveryCost = (float) ($this->input('delivery_cost') ?? 0); // Obtener costo envío

            if (is_array($items) && ! empty($items)) {
                $calculatedItemsTotal = 0.0;

                foreach ($items as $item) {
                    // Validar que los datos del item sean numéricos antes de usarlos
                    $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 0;
                    $unitPrice = isset($item['unit_price']) && is_numeric($item['unit_price']) ? (float) $item['unit_price'] : 0.0;

                     // Asegurarse de que qty y unitPrice sean válidos para evitar errores
                    if ($qty > 0 && $unitPrice >= 0) {
                         $calculatedItemsTotal += $qty * $unitPrice;
                    } else {
                         // Si un item tiene datos inválidos, añadir error y detener validación de depósito
                         $v->errors()->add('items', 'Uno o más ítems tienen cantidad o precio inválido.');
                         return; // Salir de la función after
                    }

                }

                // Calcular el total general incluyendo el envío
                $calculatedGrandTotal = $calculatedItemsTotal + $deliveryCost;

                $deposit = (float) ($this->input('deposit') ?? 0);

                // Comparar depósito con el total general
                // Usar una pequeña tolerancia (epsilon) para comparaciones de flotantes
                $epsilon = 0.01;
                if ($deposit > ($calculatedGrandTotal + $epsilon)) {
                    $v->errors()->add(
                        'deposit',
                        'El depósito (\$'.number_format($deposit, 0, ',', '.').') no puede ser mayor al total del pedido (\$'.number_format($calculatedGrandTotal, 0, ',', '.').').'
                    );
                }
            } elseif ($this->input('deposit') > 0) {
                 // Si no hay items pero se envió un depósito > 0, es un error
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
        // Si la fecha viene en otro formato (ej. dd/MM/yyyy), conviértela a Y-m-d
        if ($this->has('event_date')) {
            try {
                // Intenta parsear como Y-m-d primero (formato estándar)
                \DateTime::createFromFormat('Y-m-d', $this->event_date);
            } catch (\Exception $e) {
                 // Si falla, intenta parsear como d/m/Y (formato común de input)
                 try {
                     $date = \DateTime::createFromFormat('d/m/Y', $this->event_date);
                     if ($date) {
                         $this->merge(['event_date' => $date->format('Y-m-d')]);
                     }
                 } catch (\Exception $e2) {
                     // Si ambos fallan, la validación 'date_format:Y-m-d' fallará
                 }
            }
        }

       // Limpiar números (quitar separadores de miles, reemplazar coma decimal)
        if ($this->has('deposit')) {
            $this->merge(['deposit' => str_replace(',', '.', preg_replace('/[^\d,]/', '', $this->deposit))]);
        }
        if ($this->has('delivery_cost')) {
            $this->merge(['delivery_cost' => str_replace(',', '.', preg_replace('/[^\d,]/', '', $this->delivery_cost))]);
        }
         // Limpiar unit_price en items
         if ($this->has('items') && is_array($this->items)) {
             $cleanedItems = [];
             foreach ($this->items as $key => $item) {
                 if (isset($item['unit_price'])) {
                     $item['unit_price'] = str_replace(',', '.', preg_replace('/[^\d,]/', '', (string)$item['unit_price']));
                 }
                 $cleanedItems[$key] = $item;
             }
             $this->merge(['items' => $cleanedItems]);
         }
    }
}
