<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$orders = \App\Models\Order::with('items')->get();
$emptyCount = 0;
foreach($orders as $o) {
    if ($o->items->count() === 0) {
        $emptyCount++;
    }
}
echo "Total orders: " . $orders->count() . "\n";
echo "Orders with 0 items: " . $emptyCount . "\n";
