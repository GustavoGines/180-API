<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $note = App\Models\CopilotNote::create([
        'user_id' => 1,
        'content' => 'test',
        'ui_widget' => ['type'=>'test'],
        'source_context' => 'test'
    ]);
    echo "SUCCESS: " . $note->id . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
