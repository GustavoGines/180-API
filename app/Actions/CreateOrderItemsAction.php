<?php

namespace App\Actions;

use App\Models\Order;

class CreateOrderItemsAction
{
    public function execute(Order $order, array $itemsDataRaw)
    {
        $itemsData = array_map(function ($item) {
            return [
                'name' => $item['name'],
                'qty' => $item['qty'],
                'base_price' => $item['base_price'],
                'adjustments' => $item['adjustments'] ?? 0,
                'customization_notes' => $item['customization_notes'] ?? null,
                'customization_json' => isset($item['customization_json']) && is_array($item['customization_json'])
                                        ? $item['customization_json']
                                        : null,
            ];
        }, $itemsDataRaw);

        $order->items()->createMany($itemsData);
    }
}
