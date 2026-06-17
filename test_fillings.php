<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app('App\Http\Controllers\CopilotController');
$fNames = ['Mousse de chocolate', 'Mousse de frutilla'];
foreach ($fNames as $fName) {
    $match = $controller->findBestMatch($fName, App\Models\Filling::all(), 'name', 50);
    echo $fName.' -> '.($match ? $match->name : 'NULL').PHP_EOL;
}
