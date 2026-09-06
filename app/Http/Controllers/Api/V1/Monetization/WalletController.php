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
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $validated = $request->validate([
            'amount_minor_units' => 'required|integer|min:100',
        ]);

        $amountMinor = (int)$validated['amount_minor_units'];
        $amountDecimal = number_format($amountMinor / 100.0, 2, '.', '');

        // Generate Razorpay Order
        $keyId = env('RAZORPAY_KEY_ID', 'rzp_live_DrmCn9LyTbOEwb');
        $keySecret = env('RAZORPAY_KEY_SECRET', 'ADVWusim1YO3hLvbKY8QyfVB');
        $gatewayOrderId = null;

        try {
            $rzpResponse = \Illuminate\Support\Facades\Http::withBasicAuth($keyId, $keySecret)
                ->timeout(10)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount'   => $amountMinor,
                    'currency' => 'INR',
                    'receipt'  => 'recharge_' . $user->id . '_' . time(),
                ]);

            if ($rzpResponse->successful()) {
                $gatewayOrderId = $rzpResponse->json('id');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Razorpay order initiation error: ' . $e->getMessage());
        }

        // Fallback order ID if Razorpay network call times out
        if (!$gatewayOrderId) {
            $gatewayOrderId = 'order_' . bin2hex(random_bytes(10));
        }

        // Create recharge_request record
        $rechargeId = DB::table('recharge_requests')->insertGetId([
            'user_id'               => $user->id,
            'requested_amount'      => $amountDecimal,
            'approved_amount'       => null,
            'status'                => 'pending',
            'payment_method'        => 'razorpay',
            'transaction_reference' => $gatewayOrderId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return response()->json([
            'data' => [
                'id'                 => (int)$rechargeId,
                'amount_minor_units' => $amountMinor,
                'currency'           => 'INR',
                'status'             => 'pending',
                'gateway_order_id'   => $gatewayOrderId,
                'paid_at'            => null,
                'created_at'         => now()->toISOString(),
                'user'               => [
                    'id'    => (int)$user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function verifyTopUp(Request $request, $id)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');

        $recharge = DB::table('recharge_requests')->where('id', $id)->where('user_id', $user->id)->first();
        if ($recharge) {
            DB::table('recharge_requests')->where('id', $id)->update([
                'transaction_reference' => $paymentId ?: $recharge->transaction_reference,
                'updated_at'            => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'id'                 => (int)$id,
                'amount_minor_units' => $recharge ? (int)round(((float)$recharge->requested_amount) * 100) : 0,
                'currency'           => 'INR',
                'status'             => 'pending',
                'gateway_order_id'   => $recharge->transaction_reference ?? null,
                'gateway_payment_id' => $paymentId,
                'paid_at'            => now()->toISOString(),
                'created_at'         => $recharge->created_at ?? now()->toISOString(),
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
