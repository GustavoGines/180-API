<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Filling;
use App\Models\Extra;
use Illuminate\Support\Facades\Log;

class BotOrderService
{
    /**
     * Translates an array of bot items into the standard array format expected
     * by OrderController->createOrderItems(), populating customization_json with IDs.
     *
     * @param array $botItems
     * @return array
     */
    public function translateBotItems(array $botItems): array
    {
        $translatedItems = [];

        foreach ($botItems as $botItem) {
            $translatedItem = [
                'name' => $botItem['product_name'],
                'qty' => $botItem['qty'],
                'base_price' => $botItem['base_price'],
                'adjustments' => $botItem['adjustments'] ?? 0,
                'customization_notes' => $botItem['customization_notes'] ?? null,
                'customization_json' => [
                    'category' => $botItem['category_name'],
                    // Flutter usa string keys para category, weight formatea a double
                ],
            ];

            // 1. Buscar el Product ID por nombre y categoria
            $productNameClean = strtolower(trim($botItem['product_name']));
            $product = Product::whereRaw('LOWER(name) = ?', [$productNameClean])
                ->where('category', $botItem['category_name'])
                ->first();

            if ($product) {
                // Incorporamos data util para edicion futura (Flutter)
                $translatedItem['customization_json']['product_id'] = $product->id;
            } else {
                Log::warning("BotOrderService: Product not found by bot: {$botItem['product_name']} in category {$botItem['category_name']}");
            }

            // 2. Weight
            if (isset($botItem['weight_kg'])) {
                $translatedItem['customization_json']['weight_kg'] = (float) $botItem['weight_kg'];
            }

            // 3. Fillings (Rellenos)
            if (!empty($botItem['fillings']) && is_array($botItem['fillings'])) {
                $fillingNames = array_map(fn($name) => strtolower(trim($name)), $botItem['fillings']);
                $fillings = Filling::where(function($query) use ($fillingNames) {
                    foreach ($fillingNames as $name) {
                        $query->orWhereRaw('LOWER(name) = ?', [$name]);
                    }
                })->get();
                $selectedFillings = [];
                
                foreach ($fillings as $filling) {
                    $selectedFillings[] = [
                        'id' => $filling->id,
                        'name' => $filling->name,
                        'price_per_kg' => $filling->price_per_kg,
                        'is_free' => (bool)$filling->is_free
                    ];
                }

                if (count($selectedFillings) !== count($botItem['fillings'])) {
                    Log::warning("BotOrderService: Some fillings not found for bot item {$botItem['product_name']}", $botItem['fillings']);
                }

                $translatedItem['customization_json']['selected_fillings'] = $selectedFillings;
            } else {
                $translatedItem['customization_json']['selected_fillings'] = [];
            }

            // 4. Extras
            if (!empty($botItem['extras']) && is_array($botItem['extras'])) {
                $extraNames = array_map(fn($name) => strtolower(trim($name)), $botItem['extras']);
                $extras = Extra::where(function($query) use ($extraNames) {
                    foreach ($extraNames as $name) {
                        $query->orWhereRaw('LOWER(name) = ?', [$name]);
                    }
                })->get();
                $selectedExtras = [];

                foreach ($extras as $extra) {
                    $selectedExtras[] = [
                        'id' => $extra->id,
                        'name' => $extra->name,
                        'price' => $extra->price,
                    ];
                }

                if (count($selectedExtras) !== count($botItem['extras'])) {
                    Log::warning("BotOrderService: Some extras not found for bot item {$botItem['product_name']}", $botItem['extras']);
                }

                $translatedItem['customization_json']['extras'] = $selectedExtras;
            } else {
                $translatedItem['customization_json']['extras'] = [];
            }

            // 5. Otros campos nativos de Flutter (opcionales pero útiles)
            $translatedItem['customization_json']['photo_urls'] = [];
            $translatedItem['customization_json']['calculated_final_unit_price'] = (float)$botItem['base_price'] + (float)($botItem['adjustments'] ?? 0);

            $translatedItems[] = $translatedItem;
        }

        return $translatedItems;
    }
}
