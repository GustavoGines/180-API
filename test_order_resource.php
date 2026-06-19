<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$order = \App\Models\Order::with(['items', 'client'])->first();
echo json_encode((new \App\Http\Resources\OrderResource($order))->toArray(request()), JSON_PRETTY_PRINT);
