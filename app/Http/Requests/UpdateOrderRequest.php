<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOrderRequest extends FormRequest
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
        // Reglas muy similares al Store, pero quizás 'event_date' no sea obligatorio si es PATCH?
        // El controller actual usa PUT y valida todo de nuevo, así que mantendremos 'required'.
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'client_address_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(function () {
                    return ($this->input('delivery_cost') ?? 0) > 0;
                }),
                Rule::exists('client_addresses', 'id')->where(function ($query) {
                    return $query->where('client_id', $this->input('client_id'));
                }),
            ],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'], // Controller dice required
            'end_time' => ['required', 'date_format:H:i'],   // Controller dice required

            'status' => ['nullable', 'string', 'in:confirmed,ready,delivered,canceled'],
            'is_paid' => ['nullable', 'boolean'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            // En update, los items pueden tener ID o no (si son nuevos agregados en la edición)
            'items.*.id' => ['nullable', 'integer', 'exists:order_items,id'], // Opcional verificar que pertenezcan a ESTA orden
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.base_price' => ['required', 'numeric', 'min:0'],
            'items.*.adjustments' => ['nullable', 'numeric'],
            'items.*.customization_notes' => ['nullable', 'string'],
            'items.*.customization_json' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // 1) Hora
            $date = $this->input('event_date');
            $start = $this->input('start_time');
            $end = $this->input('end_time');

            if ($date && $start && $end) {
                try {
                    $eventDate = \Carbon\Carbon::parse($date, config('app.timezone'));
                    $startTime = $eventDate->copy()->setTimeFromTimeString($start);
                    $endTime = $eventDate->copy()->setTimeFromTimeString($end);

                    if ($endTime->lt($startTime)) {
                        $endTime->addDay();
                    }

                    if ($endTime->lte($startTime)) {
                        $v->errors()->add('end_time', 'La hora de fin debe ser posterior a la hora de inicio.');
                    }
                } catch (\Exception $e) {
                    $v->errors()->add('event_date', 'Formato de fecha u hora inválido.');
                }
            }

            // 2) Depósito
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
                        $v->errors()->add("items.$key", 'El precio final del ítem no puede ser negativo.');

                        continue;
                    }

                    $calculatedItemsTotal += $qty * $finalUnitPrice;
                }

                if (! $v->errors()->has('items.*')) {
                    $calculatedGrandTotal = $calculatedItemsTotal + $deliveryCost;
                    $deposit = (float) ($this->input('deposit') ?? 0);
                    $epsilon = 0.01;

                    if ($deposit > ($calculatedGrandTotal + $epsilon)) {
                        $v->errors()->add('deposit', 'El depósito no puede ser mayor al total.');
                    }
                }
            } elseif (($this->input('deposit') ?? 0) > 0) {
                $v->errors()->add('deposit', 'No se puede registrar un depósito si no hay productos.');
            }
        });
    }

    protected function prepareForValidation()
    {
        // 1. Decodificar payload
        if ($this->has('order_payload')) {
            $payload = json_decode($this->input('order_payload'), true);
            if (is_array($payload)) {
                $this->merge($payload);
            }
        }

        // 2. Limpiar items
        if ($this->has('items') && is_array($this->items)) {
            $cleanedItems = [];
            foreach ($this->items as $item) {
                foreach (['base_price', 'adjustments'] as $f) {
                    if (isset($item[$f])) {
                        $item[$f] = str_replace(',', '.', preg_replace('/[^\d\-,.]/', '', (string) $item[$f]));
                    }
                }
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
