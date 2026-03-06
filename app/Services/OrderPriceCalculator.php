<?php

namespace App\Services;

class OrderPriceCalculator
{
    /**
     * Calcula el total de los items.
     *
     * @param array $items
     * @return float
     */
    public function calculateItemsTotal(array $items): float
    {
        $itemsTotal = 0.0;
        foreach ($items as $item) {
            $qty = (int) $item['qty'];
            $basePrice = (float) $item['base_price'];
            $adjustments = (float) ($item['adjustments'] ?? 0);
            $finalUnitPrice = $basePrice + $adjustments;
            $itemsTotal += $qty * $finalUnitPrice;
        }

        return $itemsTotal;
    }

    /**
     * Calcula el gran total incluyendo delivery.
     *
     * @param float $itemsTotal
     * @param float $deliveryCost
     * @return float
     */
    public function calculateGrandTotal(float $itemsTotal, float $deliveryCost): float
    {
        return $itemsTotal + $deliveryCost;
    }

    /**
     * Calcula el depósito o seña válido (no mayor al total).
     *
     * @param float $deposit
     * @param float $grandTotal
     * @return float
     */
    public function calculateValidDeposit(float $deposit, float $grandTotal): float
    {
        return min($deposit, $grandTotal);
    }
}
