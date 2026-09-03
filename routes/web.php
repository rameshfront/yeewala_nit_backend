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
            mkdir($dir, 0775, true);
        }
    }
    \Illuminate\Support\Facades\Artisan::call('optimize:clear');
    return response()->json([
        'status' => 'success',
        'message' => 'Storage directories created & cache cleared successfully!',
    ]);
});
