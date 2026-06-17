<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$request = Illuminate\Http\Request::create('/api/copilot/process', 'POST', ['messages' => [['role' => 'user', 'content' => 'búscame los pedidos de Lorena'], ['role' => 'assistant', 'content' => 'No se encontraron pedidos registrados para un cliente llamado Lorena.'], ['role' => 'user', 'content' => 'Lorena Caballero']]]);
$controller = new App\Http\Controllers\CopilotController;
$response = $controller->process($request);
echo $response->getContent();
