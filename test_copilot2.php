<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\CopilotController;
use App\Models\OrderItem;

try {
    $controller = new CopilotController;
    $method = new ReflectionMethod(CopilotController::class, 'executeLocalTool');
    $method->setAccessible(true);

    // TEST 1: Torta de 3.5kg con Mousse de Chocolate (Extra) y Crema (Gratis)
    echo "\nTEST 1: Torta de 3.5kg con Rellenos mixtos\n";
    $args1 = [
        'client_name' => 'Prueba Parseo',
        'event_date' => '2026-06-25',
        'items' => [
            [
                'product_name' => 'Torta Clásica (Base)',
                'quantity' => 1,
                'weight_kg' => 3.5,
                'fillings' => ['Mousse de Chocolate', 'Crema Chantilly'],
                'notes' => 'Torta de 3.5 kg',
            ],
        ],
    ];
    $result1 = $method->invokeArgs($controller, ['create_order', $args1]);
    if ($result1['success']) {
        $orderId = $result1['order']->id;
        $item = OrderItem::where('order_id', $orderId)->first();
        echo "Exito! Item guardado:\n";
        echo 'Base Price guardado: '.$item->base_price."\n";
        echo 'JSON: '.json_encode($item->customization_json, JSON_PRETTY_PRINT)."\n";
    }

    // TEST 2: 18 Cupcakes (Unidades)
    echo "\nTEST 2: 18 Cupcakes (Unidades)\n";
    $args2 = [
        'client_name' => 'Prueba Parseo',
        'event_date' => '2026-06-25',
        'items' => [
            [
                'product_name' => 'Cupcakes',
                'quantity' => 18,
                'is_unit_sale' => true,
                'notes' => '18 cupcakes',
            ],
        ],
    ];
    $result2 = $method->invokeArgs($controller, ['create_order', $args2]);
    if ($result2['success']) {
        $orderId = $result2['order']->id;
        $item = OrderItem::where('order_id', $orderId)->first();
        echo "Exito! Item guardado:\n";
        echo 'Base Price guardado: '.$item->base_price."\n";
        echo 'Total guardado: '.$result2['order']->total."\n";
        echo 'JSON: '.json_encode($item->customization_json, JSON_PRETTY_PRINT)."\n";
    }

} catch (\Exception $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
