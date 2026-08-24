<?php

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
