<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Video\VideoController;
use App\Http\Controllers\Api\V1\Monetization\WalletController;
use App\Http\Controllers\Api\V1\Creator\CreatorController;
use App\Http\Controllers\Api\V1\Engagement\HistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/avatar', [AuthController::class, 'updateAvatar']);

    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/mine', [VideoController::class, 'myVideos']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);
    Route::get('/feed/home', [VideoController::class, 'homeFeed']);
    Route::get('/feed/trending', [VideoController::class, 'trendingFeed']);
    Route::get('/feed/latest', [VideoController::class, 'latestFeed']);
    Route::get('/feed/recommended', [VideoController::class, 'recommendedFeed']);
    Route::get('/feed/following', [VideoController::class, 'followingFeed']);
    Route::get('/feed/continue-watching', [VideoController::class, 'continueWatching']);
    Route::get('/notifications', [VideoController::class, 'notifications']);

    // Wallet & Video Purchases
    Route::get('/wallet', [WalletController::class, 'getMyWallet']);
    Route::post('/wallet/topups', [WalletController::class, 'topUp']);
    Route::get('/me/purchased-videos', [WalletController::class, 'listPurchasedVideos']);
    Route::post('/wallet/videos/{id}/purchase', [WalletController::class, 'purchaseVideo']);
    Route::post('/admin/users/{userId}/wallet/credit', [WalletController::class, 'adminCreditWallet']);

    // Creator Profile & Channel Pages
    Route::get('/creator/dashboard', [CreatorController::class, 'getDashboard']);
    Route::get('/creator/profile', [CreatorController::class, 'getProfile']);
    Route::get('/me/following', [CreatorController::class, 'listFollowing']);
    Route::get('/creators/{id}', [CreatorController::class, 'show']);
    Route::get('/creators/{id}/videos', [CreatorController::class, 'getCreatorVideos']);
    Route::post('/creators/{id}/follow', [CreatorController::class, 'follow']);
    Route::delete('/creators/{id}/follow', [CreatorController::class, 'unfollow']);

    // Search Routes
    Route::get('/search/videos', [VideoController::class, 'searchVideos']);
    Route::get('/search/creators', [VideoController::class, 'searchCreators']);
    Route::get('/search/categories', [VideoController::class, 'searchCategories']);
    Route::get('/search/tags', [VideoController::class, 'searchTags']);

    // Watch History Routes
    Route::get('/me/history', [HistoryController::class, 'index']);
    Route::delete('/me/history/{videoId}', [HistoryController::class, 'destroy']);
    Route::delete('/me/history', [HistoryController::class, 'clear']);
    Route::put('/videos/{videoId}/progress', [HistoryController::class, 'syncProgress']);
});
