<?php

namespace App\Services;

use Illuminate\Validation\Validator;

class OrderCalculatorService
{
    public function validateOrder(Validator $v, $items, float $deliveryCost, float $deposit)
    {
        if (is_array($items) && ! empty($items)) {
            $calculatedItemsTotal = 0.0;
            foreach ($items as $key => $item) {
                $qty = isset($item['qty']) && is_numeric($item['qty']) ? (float) $item['qty'] : 0;
                $basePrice = isset($item['base_price']) && is_numeric($item['base_price']) ? (float) $item['base_price'] : -1.0;
                $adjustments = isset($item['adjustments']) && is_numeric($item['adjustments']) ? (float) $item['adjustments'] : 0.0;

                if ($qty <= 0 || $basePrice < 0) {
                    $v->errors()->add("items.$key", __('messages.validation.item_invalid_qty_price'));

                    continue;
                }

                $finalUnitPrice = $basePrice + $adjustments;
                if ($finalUnitPrice < 0) {
                    $v->errors()->add("items.$key", __('messages.validation.item_price_negative'));

                    continue;
                }

                $calculatedItemsTotal += $qty * $finalUnitPrice;
            }

            if (! $v->errors()->has('items.*')) {
                $calculatedGrandTotal = $calculatedItemsTotal + $deliveryCost;
                $epsilon = 0.01;

                if ($deposit > ($calculatedGrandTotal + $epsilon)) {
                    $v->errors()->add('deposit', __('messages.validation.deposit_exceeds_total'));
                }
            }
        } elseif ($deposit > 0) {
            $v->errors()->add('deposit', __('messages.validation.deposit_without_items'));
        }
    }
}
