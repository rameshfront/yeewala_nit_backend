<?php

namespace App\Http\Controllers\Api\V1\Monetization;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * Get or create current user's wallet with balance.
     */
    public function getMyWallet(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $wallet = DB::table('wallets')
            ->where('owner_id', $user->id)
            ->where('type', 'user')
            ->first();

        if (!$wallet) {
            $walletId = DB::table('wallets')->insertGetId([
                'type' => 'user',
                'owner_id' => $user->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $wallet = DB::table('wallets')->where('id', $walletId)->first();
        }

        $balance = $this->calculateWalletBalance($wallet->id);

        return response()->json([
            'data' => [
                'id' => (int)$wallet->id,
                'type' => $wallet->type,
                'owner_id' => (int)$wallet->owner_id,
                'currency' => $wallet->currency,
                'available_balance_minor_units' => $balance,
                'pending_balance_minor_units' => 0,
                'created_at' => $wallet->created_at,
                'updated_at' => $wallet->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * List purchased videos for current user.
     */
    public function listPurchasedVideos(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $purchases = DB::table('video_purchases')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn($p) => [
                'id' => (int)$p->id,
                'user_id' => (int)$p->user_id,
                'video_id' => (int)$p->video_id,
                'price_minor_units' => (int)$p->price_minor_units,
                'currency' => $p->currency,
                'purchased_at' => $p->purchased_at,
                'created_at' => $p->created_at,
                'updated_at' => $p->updated_at,
            ]);

        return response()->json([
            'data' => $purchases,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Purchase a video using wallet balance.
     */
    public function purchaseVideo(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $video = DB::table('videos')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$video) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']]], 404);
        }

        // Check if already purchased
        $existing = DB::table('video_purchases')->where('user_id', $user->id)->where('video_id', $id)->first();
        if ($existing) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'ALREADY_PURCHASED', 'message' => 'Video already purchased']]], 400);
        }

        $price = (int)($video->price_minor_units ?? 0);
        if ($price <= 0) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'FREE_VIDEO', 'message' => 'This video is free']]], 400);
        }

        // Get user wallet
        $wallet = DB::table('wallets')->where('owner_id', $user->id)->where('type', 'user')->first();
        if (!$wallet) {
            // Create wallet if missing
            $walletId = DB::table('wallets')->insertGetId([
                'type' => 'user',
                'owner_id' => $user->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = DB::table('wallets')->where('id', $walletId)->first();
        }

        $balance = $this->calculateWalletBalance($wallet->id);
        if ($balance < $price) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'INSUFFICIENT_FUNDS', 'message' => 'Insufficient wallet balance']]], 400);
        }

        // Perform purchase transaction
        DB::beginTransaction();
        try {
            // 1. Create video_purchases record
            $purchaseId = DB::table('video_purchases')->insertGetId([
                'user_id' => $user->id,
                'video_id' => $id,
                'price_minor_units' => $price,
                'currency' => 'INR',
                'purchased_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Debit user wallet
            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'debit',
                'category' => 'purchase',
                'amount_minor_units' => $price,
                'status' => 'cleared',
                'source_type' => 'App\\Domain\\Monetization\\Models\\VideoPurchase',
                'source_id' => $purchaseId,
                'description' => "Purchase of video #{$id}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            $newBalance = $this->calculateWalletBalance($wallet->id);

            return response()->json([
                'data' => [
                    'purchase' => [
                        'id' => (int)$purchaseId,
                        'user_id' => (int)$user->id,
                        'video_id' => (int)$id,
                        'price_minor_units' => $price,
                        'currency' => 'INR',
                        'purchased_at' => now()->toIso8601String(),
                    ],
                    'wallet' => [
                        'id' => (int)$wallet->id,
                        'available_balance_minor_units' => $newBalance,
                    ]
                ],
                'meta' => null,
                'errors' => null,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'TRANSACTION_FAILED', 'message' => 'Failed to process purchase']]], 500);
        }
    }

    /**
     * Top up wallet balance (for testing / manual top-up).
     */
    public function topUp(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $validated = $request->validate([
            'amount_minor_units' => 'required|integer|min:100',
        ]);

        $wallet = DB::table('wallets')->where('owner_id', $user->id)->where('type', 'user')->first();
        if (!$wallet) {
            $walletId = DB::table('wallets')->insertGetId([
                'type' => 'user',
                'owner_id' => $user->id,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = DB::table('wallets')->where('id', $walletId)->first();
        }

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'category' => 'top_up',
            'amount_minor_units' => $validated['amount_minor_units'],
            'status' => 'cleared',
            'description' => 'Wallet top up',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newBalance = $this->calculateWalletBalance($wallet->id);

        return response()->json([
            'data' => [
                'id' => (int)$wallet->id,
                'type' => $wallet->type,
                'owner_id' => (int)$wallet->owner_id,
                'currency' => $wallet->currency,
                'available_balance_minor_units' => $newBalance,
                'pending_balance_minor_units' => 0,
                'created_at' => $wallet->created_at,
                'updated_at' => $wallet->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    private function calculateWalletBalance(int $walletId): int
    {
        $credits = (int)DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->where('type', 'credit')
            ->where('status', 'cleared')
            ->sum('amount_minor_units');

        $debits = (int)DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->where('type', 'debit')
            ->where('status', 'cleared')
            ->sum('amount_minor_units');

        return max(0, $credits - $debits);
    }

    /**
     * Admin credits balance to a user's wallet upon approval.
     */
    public function adminCreditWallet(Request $request, $userId)
    {
        $validated = $request->validate([
            'amount_minor_units' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $wallet = DB::table('wallets')->where('owner_id', $userId)->where('type', 'user')->first();
        if (!$wallet) {
            $walletId = DB::table('wallets')->insertGetId([
                'type' => 'user',
                'owner_id' => $userId,
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = DB::table('wallets')->where('id', $walletId)->first();
        }

        DB::table('wallet_transactions')->insert([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'category' => 'manual_adjustment',
            'amount_minor_units' => $validated['amount_minor_units'],
            'status' => 'cleared',
            'description' => $validated['description'] ?? 'Admin wallet approval/credit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newBalance = $this->calculateWalletBalance($wallet->id);

        return response()->json([
            'data' => [
                'user_id' => (int)$userId,
                'wallet_id' => (int)$wallet->id,
                'available_balance_minor_units' => $newBalance,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
