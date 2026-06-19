<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = \App\Models\User::first();
\Illuminate\Support\Facades\Auth::login($user);

$request = \Illuminate\Http\Request::create('/api/orders?from=2026-06-01&to=2026-06-30&per_page=500', 'GET');
$response = app()->handle($request);
$data = json_decode($response->getContent(), true);

$emptyItemsCount = 0;
foreach($data['data'] as $o) {
    if (empty($o['items'])) {
        $emptyItemsCount++;
    }
}
echo "Total returned: " . count($data['data']) . "\n";
echo "Empty items: " . $emptyItemsCount . "\n";
