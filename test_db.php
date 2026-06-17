<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$c = App\Models\Client::where('name', 'Lorena Caballero')->first();
$o = App\Models\Order::with(['client', 'items'])->where('client_id', $c->id)->get();
echo json_last_error_msg();
$json = json_encode(['success' => true, 'orders' => $o]);
echo '\nLastError ToolResponse: '.json_last_error_msg();
$payload = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'tool', 'content' => $json]]];
$guzzleJson = json_encode($payload);
echo '\nLastError Guzzle: '.json_last_error_msg();
