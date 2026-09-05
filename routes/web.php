<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;


Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'Video Platform API Backend is running successfully',
        'version' => '1.0.0'
    ]);
});

Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
});

Route::get('/clear', function () {
    $directories = [
        storage_path('framework/cache/data'),
        storage_path('framework/sessions'),
        storage_path('framework/views'),
        storage_path('logs'),
    ];
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    $results = [];
    foreach (['config:clear', 'route:clear', 'cache:clear'] as $cmd) {
        try {
            \Illuminate\Support\Facades\Artisan::call($cmd);
            $results[$cmd] = 'OK';
        } catch (\Throwable $e) {
            $results[$cmd] = 'Error: ' . $e->getMessage();
        }
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Cache cleared successfully!',
        'results' => $results,
    ]);
});
