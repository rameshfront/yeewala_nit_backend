<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Auto-ensure storage framework directories exist so View Compiler never fails
foreach ([
    __DIR__.'/../storage/framework/views',
    __DIR__.'/../storage/framework/cache/data',
    __DIR__.'/../storage/framework/sessions',
    __DIR__.'/../storage/logs',
] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

// Emergency cache buster on shared hosting to force reload fresh routes & config
if (isset($_GET['cache_bust_clear'])) {
    $cleared = [];
    foreach (glob(__DIR__.'/../bootstrap/cache/*.php') as $cacheFile) {
        if (!str_contains($cacheFile, 'packages.php') && !str_contains($cacheFile, 'services.php')) {
            @unlink($cacheFile);
            $cleared[] = basename($cacheFile);
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Cache files removed!', 'cleared' => $cleared]);
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());

