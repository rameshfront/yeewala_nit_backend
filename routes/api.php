<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Video\VideoController;
use App\Http\Controllers\Api\V1\Monetization\WalletController;
use App\Http\Controllers\Api\V1\Monetization\OrderController;
use App\Http\Controllers\Api\V1\Creator\CreatorController;
use App\Http\Controllers\Api\V1\Analytics\CreatorAnalyticsController;
use App\Http\Controllers\Api\V1\Engagement\HistoryController;
use App\Http\Controllers\Api\V1\Engagement\EngagementController;
use App\Http\Controllers\Api\V1\Admin\SettingsController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminLocationController;
use App\Http\Controllers\Api\V1\Location\LocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Public Location Routes (Countries & States)
    Route::get('/countries', [LocationController::class, 'countries']);
    Route::get('/countries/{countryId}/states', [LocationController::class, 'states']);

    // Admin Settings, Locations & Dashboard
    Route::get('/admin/settings', [SettingsController::class, 'index']);
    Route::patch('/admin/settings/{group}', [SettingsController::class, 'update']);
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'getDashboard']);

    Route::get('/admin/countries', [AdminLocationController::class, 'listCountries']);
    Route::post('/admin/countries', [AdminLocationController::class, 'storeCountry']);
    Route::get('/admin/countries/{id}', [AdminLocationController::class, 'showCountry']);
    Route::match(['put', 'patch'], '/admin/countries/{id}', [AdminLocationController::class, 'updateCountry']);
    Route::delete('/admin/countries/{id}', [AdminLocationController::class, 'destroyCountry']);

    Route::get('/admin/states', [AdminLocationController::class, 'listStates']);
    Route::post('/admin/states', [AdminLocationController::class, 'storeState']);
    Route::get('/admin/states/{id}', [AdminLocationController::class, 'showState']);
    Route::match(['put', 'patch'], '/admin/states/{id}', [AdminLocationController::class, 'updateState']);
    Route::delete('/admin/states/{id}', [AdminLocationController::class, 'destroyState']);

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/google', [GoogleAuthController::class, 'authWithGoogle']);
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/avatar', [AuthController::class, 'updateAvatar']);

    // Email & Phone Verification Routes
    Route::post('/auth/email/resend', [AuthController::class, 'resendVerificationEmail']);
    Route::get('/auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail']);
    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/phone/send-code', [AuthController::class, 'sendPhoneVerificationCode']);
    Route::post('/auth/phone/verify', [AuthController::class, 'verifyPhoneCode']);

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
    Route::get('/wallet/balances', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'getWalletBalances']);
    Route::post('/wallet/topups', [WalletController::class, 'topUp']);
    Route::get('/me/purchased-videos', [WalletController::class, 'listPurchasedVideos']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::get('/orders/{id}/status', [OrderController::class, 'status']);
    Route::post('/wallet/videos/{id}/purchase', [WalletController::class, 'purchaseVideo']);
    Route::post('/wallet/purchase', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'purchaseVideos']);
    Route::post('/wallet/withdraw', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'requestWithdrawal']);
    Route::post('/admin/users/{userId}/wallet/credit', [WalletController::class, 'adminCreditWallet']);
    Route::post('/admin/wallet/approve-earnings', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'approveEarnings']);
    Route::post('/admin/wallet/withdrawals/{id}/approve', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'approveWithdrawal']);
    Route::post('/admin/wallet/withdrawals/{id}/reject', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'rejectWithdrawal']);
    Route::post('/admin/wallet/approve-recharge', [\App\Http\Controllers\Api\V1\Monetization\WalletOperationController::class, 'approveRecharge']);

    // Creator Profile & Channel Pages
    Route::get('/creator/dashboard', [CreatorController::class, 'getDashboard']);
    Route::get('/creator/profile', [CreatorController::class, 'getProfile']);
    Route::get('/creator/analytics/videos', [CreatorAnalyticsController::class, 'videos']);
    Route::get('/creator/analytics/overview', [CreatorAnalyticsController::class, 'overview']);
    Route::get('/creator/analytics/trends', [CreatorAnalyticsController::class, 'trends']);
    Route::get('/creator/analytics/videos/top', [CreatorAnalyticsController::class, 'topVideos']);
    Route::get('/creator/analytics/breakdown', [CreatorAnalyticsController::class, 'breakdown']);
    Route::get('/creator/analytics/search-keywords', [CreatorAnalyticsController::class, 'searchKeywords']);
    Route::get('/me/following', [CreatorController::class, 'listFollowing']);
    Route::get('/me/followers', [CreatorController::class, 'listFollowers']);
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

    // Video Reactions & Engagement
    Route::get('/videos/{id}/reaction', [EngagementController::class, 'getReaction']);
    Route::put('/videos/{id}/reaction', [EngagementController::class, 'setReaction'])->middleware('auth:sanctum');

    // Video Comments
    Route::get('/videos/{id}/comments', [EngagementController::class, 'listComments']);
    Route::post('/videos/{id}/comments', [EngagementController::class, 'postComment'])->middleware('auth:sanctum');
    Route::patch('/comments/{id}', [EngagementController::class, 'updateComment'])->middleware('auth:sanctum');
    Route::delete('/comments/{id}', [EngagementController::class, 'deleteComment'])->middleware('auth:sanctum');
    Route::get('/comments/{id}/replies', [EngagementController::class, 'listReplies']);
    Route::patch('/comments/{id}/heart', [EngagementController::class, 'heartComment'])->middleware('auth:sanctum');
    Route::patch('/comments/{id}/pin', [EngagementController::class, 'pinComment'])->middleware('auth:sanctum');
});
