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

        $freshUser = DB::table('users')->where('id', $user->id)->first();
        $amount = number_format((float)($freshUser->amount ?? 0), 2, '.', '');
        $earnings = number_format((float)($freshUser->earnings ?? 0), 2, '.', '');
        $actualearning = number_format((float)($freshUser->actualearning ?? 0), 2, '.', '');

        return response()->json([
            'data' => [
                'id' => (int)$wallet->id,
                'type' => $wallet->type,
                'owner_id' => (int)$wallet->owner_id,
                'currency' => $wallet->currency,
                'amount' => $amount,
                'earnings' => $earnings,
                'actualearning' => $actualearning,
                'available_balance_minor_units' => (int)round((float)$amount * 100),
                'pending_balance_minor_units' => (int)round((float)$earnings * 100),
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
     * Uses WalletService to deduct buyer amount and credit uploader pending earnings.
     */
    public function purchaseVideo(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        try {
            $walletService = app(\App\Services\Wallet\WalletService::class);
            $result = $walletService->purchaseVideos((int)$user->id, [(int)$id]);

            $video = DB::table('videos')->where('id', $id)->first();
            $priceMinorUnits = (int)round(((float)($result['total_deducted'] ?? 0)) * 100);

            return response()->json([
                'data' => [
                    'purchase' => [
                        'id' => (int)$id,
                        'user_id' => (int)$user->id,
                        'video_id' => (int)$id,
                        'price_minor_units' => $priceMinorUnits,
                        'currency' => 'INR',
                        'purchased_at' => now()->toIso8601String(),
                    ],
                    'wallet' => [
                        'amount' => $result['amount'],
                        'available_balance_minor_units' => (int)round(((float)$result['amount']) * 100),
                    ],
                ],
                'meta' => null,
                'errors' => null,
            ]);
        } catch (\App\Exceptions\Wallet\InsufficientBalanceException $e) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'INSUFFICIENT_FUNDS', 'message' => $e->getMessage()]]], 400);
        } catch (\App\Exceptions\Wallet\VideoAlreadyPurchasedException $e) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'ALREADY_PURCHASED', 'message' => $e->getMessage()]]], 400);
        } catch (\Throwable $e) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'PURCHASE_FAILED', 'message' => $e->getMessage()]]], 400);
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
