<?php

namespace App\Services;

class OrderPriceCalculator
{
    /**
     * Calcula el total de los items.
     */
    public function calculateItemsTotal(array $items): float
    {
        $itemsTotal = 0.0;
        foreach ($items as $item) {
            $qty = (float) $item['qty'];
            $basePrice = (float) $item['base_price'];
            $adjustments = (float) ($item['adjustments'] ?? 0);
            $finalUnitPrice = $basePrice + $adjustments;
            $itemsTotal += $qty * $finalUnitPrice;
        }

        return $itemsTotal;
    }

    /**
     * Calcula el gran total incluyendo delivery.
     */
    public function calculateGrandTotal(float $itemsTotal, float $deliveryCost): float
    {
        return max(0, $itemsTotal + $deliveryCost);
    }

    /**
     * Calcula el depósito o seña válido (no mayor al total).
     */
    public function calculateValidDeposit(float $deposit, float $grandTotal): float
    {
        return min($deposit, $grandTotal);
    }
}
