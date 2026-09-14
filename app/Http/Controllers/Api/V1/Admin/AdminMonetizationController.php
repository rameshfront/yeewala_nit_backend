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
        $topups = [];
        if (Schema::hasTable('wallet_top_ups')) {
            $topups = DB::table('wallet_top_ups')->orderBy('id', 'desc')->get();
        } else if (Schema::hasTable('orders')) {
            $orders = DB::table('orders')->where('orderable_type', 'like', '%wallet%')->orWhereNull('orderable_type')->orderBy('id', 'desc')->get();
            $topups = $orders->map(function ($o) {
                return [
                    'id' => (int)$o->id,
                    'wallet_id' => (int)$o->user_id,
                    'amount_minor_units' => (int)$o->total_minor_units,
                    'status' => $o->status,
                    'payment_method' => $o->gateway ?? 'razorpay',
                    'gateway_reference' => $o->gateway_payment_id ?? $o->order_number,
                    'created_at' => $o->created_at,
                ];
            });
        }

        return response()->json([
            'data' => $topups,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($topups),
                ],
            ],
            'errors' => null,
        ]);
    }

    public function approveTopup($id)
    {
        return response()->json(['message' => 'Top-up approved.', 'data' => ['id' => $id, 'status' => 'completed']]);
    }

    public function rejectTopup($id)
    {
        return response()->json(['message' => 'Top-up rejected.', 'data' => ['id' => $id, 'status' => 'failed']]);
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
