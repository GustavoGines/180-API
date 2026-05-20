<?php

namespace App\Services;

use App\Models\Extra;
use App\Models\Filling;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class BotOrderService
{
    /**
     * Translates an array of bot items into the standard array format expected
     * by OrderController->createOrderItems(), populating customization_json with IDs.
     *
     * Pre-carga todo el catálogo en memoria antes del loop para evitar N+1 queries.
     *
     * @param array $botItems
     * @return array
     */
    public function translateBotItems(array $botItems): array
    {
        // --- Pre-carga del catálogo completo en memoria (elimina N+1) ---
        // En lugar de hacer 1 query por ítem, hacemos 3 queries totales para todo el lote.

        $allProducts = Product::all()->keyBy(fn ($p) => mb_strtolower(trim($p->name), 'UTF-8') . '|' . $p->category);

        $allFillings = Filling::all()->keyBy(fn ($f) => mb_strtolower(trim($f->name), 'UTF-8'));

        $allExtras = Extra::all()->keyBy(fn ($e) => mb_strtolower(trim($e->name), 'UTF-8'));

        // --- Loop de traducción (sin queries adicionales) ---
        $translatedItems = [];

        foreach ($botItems as $botItem) {
            $translatedItem = [
                'name'                => $botItem['product_name'],
                'qty'                 => $botItem['qty'],
                'base_price'          => $botItem['base_price'],
                'adjustments'         => $botItem['adjustments'] ?? 0,
                'customization_notes' => $botItem['customization_notes'] ?? null,
                'customization_json'  => [
                    'category' => $botItem['category_name'],
                ],
            ];

            // 1. Buscar el Product por nombre+categoría en memoria
            $productKey = mb_strtolower(trim($botItem['product_name']), 'UTF-8') . '|' . $botItem['category_name'];
            $product = $allProducts->get($productKey);

            if ($product) {
                $translatedItem['customization_json']['product_id'] = $product->id;
            } else {
                Log::warning("BotOrderService: Product not found: {$botItem['product_name']} in category {$botItem['category_name']}");
            }

            // 2. Weight
            if (isset($botItem['weight_kg'])) {
                $translatedItem['customization_json']['weight_kg'] = (float) $botItem['weight_kg'];
            }

            // 3. Fillings — lookup en memoria
            $selectedFillings = [];
            if (! empty($botItem['fillings']) && is_array($botItem['fillings'])) {
                foreach ($botItem['fillings'] as $fillingName) {
                    $key = mb_strtolower(trim($fillingName), 'UTF-8');
                    $filling = $allFillings->get($key);

                    if ($filling) {
                        $selectedFillings[] = [
                            'id'           => $filling->id,
                            'name'         => $filling->name,
                            'price_per_kg' => $filling->price_per_kg,
                            'is_free'      => (bool) $filling->is_free,
                        ];
                    } else {
                        Log::warning("BotOrderService: Filling not found: {$fillingName} for item {$botItem['product_name']}");
                    }
                }
            }
            $translatedItem['customization_json']['selected_fillings'] = $selectedFillings;

            // 4. Extras — lookup en memoria
            $selectedExtras = [];
            if (! empty($botItem['extras']) && is_array($botItem['extras'])) {
                foreach ($botItem['extras'] as $extraName) {
                    $key = mb_strtolower(trim($extraName), 'UTF-8');
                    $extra = $allExtras->get($key);

                    if ($extra) {
                        $selectedExtras[] = [
                            'id'    => $extra->id,
                            'name'  => $extra->name,
                            'price' => $extra->price,
                        ];
                    } else {
                        Log::warning("BotOrderService: Extra not found: {$extraName} for item {$botItem['product_name']}");
                    }
                }
            }
            $translatedItem['customization_json']['extras'] = $selectedExtras;

            // 5. Campos nativos de Flutter
            $translatedItem['customization_json']['photo_urls'] = [];
            $translatedItem['customization_json']['calculated_final_unit_price'] =
                (float) $botItem['base_price'] + (float) ($botItem['adjustments'] ?? 0);

            $translatedItems[] = $translatedItem;
        }

        return $translatedItems;
    }
}
