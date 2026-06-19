<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::first();
\Illuminate\Support\Facades\Auth::login($user);

$request = \Illuminate\Http\Request::create('/api/orders?per_page=1', 'GET');
$response = app()->handle($request);
echo $response->getContent();
