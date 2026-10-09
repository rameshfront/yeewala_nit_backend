<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminMonetizationController extends Controller
{
    public function withdrawals(Request $request)
    {
        $status = $request->query('status');

        $query = DB::table('withdrawal_requests')
            ->leftJoin('creator_profiles', 'withdrawal_requests.wallet_id', '=', 'creator_profiles.id')
            ->select('withdrawal_requests.*', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.user_id as creator_user_id');

        if ($request->filled('status')) {
            $query->where('withdrawal_requests.status', $status);
        }

        $items = $query->orderBy('withdrawal_requests.id', 'desc')->get();

        $formatted = $items->map(function ($w) {
            $user = $w->creator_user_id ? DB::table('users')->where('id', $w->creator_user_id)->first() : null;

            return [
                'id' => (int)$w->id,
                'wallet_id' => (int)$w->wallet_id,
                'bank_detail_id' => (int)$w->bank_detail_id,
                'amount_minor_units' => (int)$w->amount_minor_units,
                'status' => $w->status,
                'creator' => [
                    'id' => (int)$w->wallet_id,
                    'user_id' => (int)($w->creator_user_id ?? 1),
                    'channel_name' => $w->channel_name ?? ($user->name ?? 'Creator'),
                    'channel_slug' => $w->channel_slug ?? 'creator',
                    'avatar_url' => null,
                    'is_verified_badge' => false,
                    'kyc_verified' => true,
                ],
                'bank_detail' => [
                    'id' => (int)$w->bank_detail_id,
                    'account_holder_name' => $user->name ?? 'Creator',
                    'masked_account_number' => '••••••••4892',
                    'ifsc_code' => 'HDFC0001234',
                    'is_current' => true,
                    'created_at' => $w->created_at,
                    'updated_at' => $w->updated_at,
                ],
                'requested_at' => $w->requested_at ?? $w->created_at,
                'reviewed_by' => $w->reviewed_by ? (int)$w->reviewed_by : null,
                'reviewed_at' => $w->reviewed_at,
                'rejection_reason' => $w->rejection_reason,
                'payout_reference' => $w->payout_reference,
                'processed_at' => $w->processed_at,
                'created_at' => $w->created_at,
                'updated_at' => $w->updated_at,
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ],
            ],
            'errors' => null,
        ]);
    }

    public function approveWithdrawal(Request $request, $id)
    {
        DB::table('withdrawal_requests')->where('id', $id)->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id() ?? 3,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        return response()->json(['data' => $w, 'meta' => null, 'errors' => null]);
    }

    public function rejectWithdrawal(Request $request, $id)
    {
        $reason = $request->input('reason', 'Withdrawal request rejected by administrator');
        DB::table('withdrawal_requests')->where('id', $id)->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => auth()->id() ?? 3,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        return response()->json(['data' => $w, 'meta' => null, 'errors' => null]);
    }

    public function processWithdrawal(Request $request, $id)
    {
        DB::table('withdrawal_requests')->where('id', $id)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        return response()->json(['data' => $w, 'meta' => null, 'errors' => null]);
    }

    public function markWithdrawalPaid(Request $request, $id)
    {
        DB::table('withdrawal_requests')->where('id', $id)->update([
            'status' => 'completed',
            'processed_at' => now(),
            'payout_reference' => 'PAY-' . strtoupper(bin2hex(random_bytes(4))),
            'updated_at' => now(),
        ]);
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        return response()->json(['data' => $w, 'meta' => null, 'errors' => null]);
    }

    public function topups(Request $request)
    {
        $status = $request->query('status'); // 'pending', 'paid', 'failed'

        $query = DB::table('recharge_requests')
            ->join('users', 'recharge_requests.user_id', '=', 'users.id')
            ->select(
                'recharge_requests.*',
                'users.name as user_name',
                'users.email as user_email'
            );

        if ($request->filled('status')) {
            $dbStatus = match ($status) {
                'paid' => 'approved',
                'failed' => 'rejected',
                default => 'pending',
            };
            $query->where('recharge_requests.status', $dbStatus);
        }

        $items = $query->orderBy('recharge_requests.id', 'desc')->get();

        $formatted = $items->map(function ($r) {
            $frontendStatus = match ($r->status) {
                'approved' => 'paid',
                'rejected' => 'failed',
                default => 'pending',
            };

            return [
                'id' => (int)$r->id,
                'amount_minor_units' => (int)round(((float)$r->requested_amount) * 100),
                'currency' => 'INR',
                'status' => $frontendStatus,
                'gateway_order_id' => $r->transaction_reference,
                'gateway_payment_id' => $r->transaction_reference,
                'paid_at' => $r->status === 'approved' ? $r->updated_at : null,
                'created_at' => $r->created_at,
                'user' => [
                    'id' => (int)$r->user_id,
                    'name' => $r->user_name,
                    'email' => $r->user_email,
                ],
            ];
        })->values()->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ],
            ],
            'errors' => null,
        ]);
    }

    public function approveTopup($id)
    {
        $recharge = DB::table('recharge_requests')->where('id', $id)->first();
        if (!$recharge) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Top-up request not found']],
            ], 404);
        }

        if ($recharge->status !== 'approved') {
            $walletService = app(\App\Services\Wallet\WalletService::class);
            $walletService->approveRecharge(
                $recharge->user_id,
                $recharge->requested_amount,
                $recharge->requested_amount,
                (int)$recharge->id
            );

            // Record transaction in wallet_transactions
            if (Schema::hasTable('wallets') && Schema::hasTable('wallet_transactions')) {
                $wallet = DB::table('wallets')->where('owner_id', $recharge->user_id)->where('type', 'user')->first();
                if (!$wallet) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'type' => 'user',
                        'owner_id' => $recharge->user_id,
                        'currency' => 'INR',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $walletId = $wallet->id;
                }

                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $walletId,
                    'type' => 'credit',
                    'category' => 'topup',
                    'amount_minor_units' => (int)round(((float)$recharge->requested_amount) * 100),
                    'status' => 'cleared',
                    'description' => 'Wallet recharge approved (Ref: ' . ($recharge->transaction_reference ?? 'N/A') . ')',
                    'created_by' => auth()->id() ?? 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create user notification
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->insert([
                    'id' => (string)\Illuminate\Support\Str::uuid(),
                    'type' => 'wallet_topup_approved',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => $recharge->user_id,
                    'data' => json_encode([
                        'type' => 'wallet_topup_approved',
                        'amount' => (float)$recharge->requested_amount,
                        'recharge_id' => (int)$recharge->id,
                        'message' => 'Your wallet top-up of ₹' . $recharge->requested_amount . ' has been approved and credited!',
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $user = DB::table('users')->where('id', $recharge->user_id)->first();

        return response()->json([
            'data' => [
                'id' => (int)$recharge->id,
                'amount_minor_units' => (int)round(((float)$recharge->requested_amount) * 100),
                'currency' => 'INR',
                'status' => 'paid',
                'gateway_order_id' => $recharge->transaction_reference,
                'gateway_payment_id' => $recharge->transaction_reference,
                'paid_at' => now()->toISOString(),
                'created_at' => $recharge->created_at,
                'user' => [
                    'id' => (int)$recharge->user_id,
                    'name' => $user->name ?? 'User',
                    'email' => $user->email ?? '',
                ],
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function rejectTopup($id)
    {
        $recharge = DB::table('recharge_requests')->where('id', $id)->first();
        if (!$recharge) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Top-up request not found']],
            ], 404);
        }

        DB::table('recharge_requests')->where('id', $id)->update([
            'status' => 'rejected',
            'updated_at' => now(),
        ]);

        // Create user notification
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->insert([
                'id' => (string)\Illuminate\Support\Str::uuid(),
                'type' => 'wallet_topup_rejected',
                'notifiable_type' => 'App\\Models\\User',
                'notifiable_id' => $recharge->user_id,
                'data' => json_encode([
                    'type' => 'wallet_topup_rejected',
                    'amount' => (float)$recharge->requested_amount,
                    'recharge_id' => (int)$recharge->id,
                    'message' => 'Your wallet top-up request of ₹' . $recharge->requested_amount . ' was rejected.',
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = DB::table('users')->where('id', $recharge->user_id)->first();

        return response()->json([
            'data' => [
                'id' => (int)$recharge->id,
                'amount_minor_units' => (int)round(((float)$recharge->requested_amount) * 100),
                'currency' => 'INR',
                'status' => 'failed',
                'gateway_order_id' => $recharge->transaction_reference,
                'gateway_payment_id' => $recharge->transaction_reference,
                'paid_at' => null,
                'created_at' => $recharge->created_at,
                'user' => [
                    'id' => (int)$recharge->user_id,
                    'name' => $user->name ?? 'User',
                    'email' => $user->email ?? '',
                ],
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function coupons(Request $request)
    {
        $coupons = [];
        if (Schema::hasTable('coupons')) {
            $coupons = DB::table('coupons')->orderBy('id', 'desc')->get();
        }

        return response()->json([
            'data' => $coupons,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($coupons),
                ],
            ],
            'errors' => null,
        ]);
    }

    public function storeCoupon(Request $request)
    {
        $code = strtoupper(trim($request->input('code', 'PROMO10')));
        $type = $request->input('type', 'percentage');
        $value = (int)$request->input('value', 10);

        $coupon = [
            'id' => rand(100, 999),
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'creator_profile_id' => null,
            'max_discount_minor_units' => $request->input('max_discount_minor_units'),
            'min_order_minor_units' => $request->input('min_order_minor_units'),
            'applicable_to' => $request->input('applicable_to'),
            'usage_limit' => $request->input('usage_limit'),
            'per_user_limit' => $request->input('per_user_limit'),
            'starts_at' => $request->input('starts_at'),
            'expires_at' => $request->input('expires_at'),
            'created_at' => now()->toIso8601String(),
        ];

        return response()->json(['data' => $coupon, 'meta' => null, 'errors' => null], 201);
    }
}
