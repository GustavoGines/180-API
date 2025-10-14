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
            'client_id'  => ['required','exists:clients,id'],
            'event_date' => ['required','date'],

            // Horarios opcionales (H:i). Si están ambos, luego validamos que end > start en withValidator()
            'start_time' => ['nullable','date_format:H:i'],
            'end_time'   => ['nullable','date_format:H:i'],

            'status'     => ['nullable','in:pending,draft,confirmed,delivered,canceled'],
            'total'      => ['nullable','numeric','min:0'],
            'deposit'    => ['nullable','numeric','min:0'],
            'notes'      => ['nullable','string'],

            'items'                     => ['required','array','min:1'],
            'items.*.name'              => ['required','string','max:120'],
            'items.*.qty'               => ['required','integer','min:1'],
            'items.*.unit_price'        => ['required','numeric','min:0'],
            'items.*.customization_json'=> ['nullable','array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // 1) Validación de horario: end_time > start_time (si ambos vienen)
            $start = $this->input('start_time');
            $end   = $this->input('end_time');
    
            if ($start && $end && strtotime($end) <= strtotime($start)) {
                $v->errors()->add('end_time', 'La hora de fin debe ser posterior a la hora de inicio.');
            }
    
            // 2) Validación de depósito: no puede ser mayor al total calculado de los ítems
            // (items es requerido por tus rules; igual chequeamos por seguridad)
            $items = $this->input('items', []);
            if (is_array($items) && !empty($items)) {
                $calculatedTotal = 0.0;
    
                foreach ($items as $item) {
                    $qty        = isset($item['qty']) ? (int)$item['qty'] : 0;
                    $unitPrice  = isset($item['unit_price']) ? (float)$item['unit_price'] : 0.0;
                    $calculatedTotal += $qty * $unitPrice;
                }
    
                $deposit = (float)($this->input('deposit') ?? 0);
    
                if ($deposit > $calculatedTotal) {
                    $v->errors()->add(
                        'deposit',
                        'El depósito no puede ser mayor al total de los ítems (' . number_format($calculatedTotal, 2, ',', '.') . ').'
                    );
                }
            }
        });
    }
}
