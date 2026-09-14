<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$hasTable = Illuminate\Support\Facades\Schema::hasTable('audit_logs');
echo "Has audit_logs table: " . ($hasTable ? 'YES' : 'NO') . "\n";
if ($hasTable) {
    echo "Columns: " . implode(', ', Illuminate\Support\Facades\Schema::getColumnListing('audit_logs')) . "\n";
}
