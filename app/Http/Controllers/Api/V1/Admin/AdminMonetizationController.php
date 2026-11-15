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
                'users.email as user_email',
                'users.amount as user_wallet_balance'
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

        $commissionPercent = (float)(DB::table('settings')->where('group', 'payment')->where('key', 'platform_commission_percent')->value('value') ?? 20);

        $formatted = $items->map(function ($r) use ($commissionPercent) {
            $frontendStatus = match ($r->status) {
                'approved' => 'paid',
                'rejected' => 'failed',
                default => 'pending',
            };

            $reqAmount = (float)$r->requested_amount;
            $appAmount = $r->approved_amount !== null ? (float)$r->approved_amount : null;
            $defaultCreditAmount = round($reqAmount * ((100 - $commissionPercent) / 100), 2);
            $commissionAmount = round($reqAmount * ($commissionPercent / 100), 2);

            return [
                'id' => (int)$r->id,
                'amount_minor_units' => (int)round($reqAmount * 100),
                'requested_amount' => $reqAmount,
                'approved_amount' => $appAmount,
                'default_credit_amount' => $defaultCreditAmount,
                'platform_commission_percent' => $commissionPercent,
                'commission_amount' => $commissionAmount,
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
                    'wallet_balance' => (float)($r->user_wallet_balance ?? 0),
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

    public function approveTopup(Request $request, $id)
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
            $commissionPercent = (float)(DB::table('settings')->where('group', 'payment')->where('key', 'platform_commission_percent')->value('value') ?? 20);
            $reqAmount = (float)$recharge->requested_amount;

            // Admin can provide custom approved_amount or use default (requested - commission)
            if ($request->filled('approved_amount') && is_numeric($request->input('approved_amount'))) {
                $approvedAmount = max(0, round((float)$request->input('approved_amount'), 2));
            } else {
                $approvedAmount = round($reqAmount * ((100 - $commissionPercent) / 100), 2);
            }

            $walletService = app(\App\Services\Wallet\WalletService::class);
            $walletService->approveRecharge(
                $recharge->user_id,
                $recharge->requested_amount,
                $approvedAmount,
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
                    'description' => "Wallet recharge (Ref: " . ($recharge->transaction_reference ?? 'N/A') . ")",
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
                        'requested_amount' => (float)$recharge->requested_amount,
                        'amount' => $approvedAmount,
                        'recharge_id' => (int)$recharge->id,
                        'message' => "Your wallet top-up request has been approved and credited.",
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $user = DB::table('users')->where('id', $recharge->user_id)->first();
        $freshRecharge = DB::table('recharge_requests')->where('id', $id)->first();
        $finalApproved = (float)($freshRecharge->approved_amount ?? $recharge->requested_amount);

        return response()->json([
            'data' => [
                'id' => (int)$recharge->id,
                'amount_minor_units' => (int)round($finalApproved * 100),
                'requested_amount' => (float)$recharge->requested_amount,
                'approved_amount' => $finalApproved,
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

    public function videoSales(Request $request)
    {
        $commissionPercent = (float)(DB::table('settings')->where('group', 'payment')->where('key', 'platform_commission_percent')->value('value') ?? 20);

        $purchasedVideoIds = DB::table('purchases')->distinct()->pluck('video_id')->toArray();

        $query = DB::table('videos')
            ->leftJoin('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->leftJoin('users', 'creator_profiles.user_id', '=', 'users.id')
            ->whereNull('videos.deleted_at')
            ->where(function ($q) use ($purchasedVideoIds) {
                $q->where('videos.price_minor_units', '>', 0)
                  ->orWhereIn('videos.id', $purchasedVideoIds);
            })
            ->select(
                'videos.id',
                'videos.title',
                'videos.type',
                'videos.thumbnail_path',
                'videos.duration_seconds',
                'videos.price_minor_units',
                'videos.created_at',
                'creator_profiles.id as creator_profile_id',
                'creator_profiles.channel_name',
                'users.id as user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.earnings as user_earnings',
                'users.actualearning as user_actualearning'
            );

        if ($request->filled('search')) {
            $search = '%' . trim($request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('videos.title', 'like', $search)
                  ->orWhere('users.name', 'like', $search)
                  ->orWhere('users.email', 'like', $search);
            });
        }

        $videos = $query->orderBy('videos.id', 'desc')->get();

        $allPurchases = DB::table('purchases')->get()->groupBy('video_id');

        $items = $videos->map(function ($v) use ($allPurchases, $commissionPercent) {
            $videoPurchases = $allPurchases->get($v->id, collect());
            $totalSales = $videoPurchases->count();
            $totalGross = (float)$videoPurchases->sum('price');

            $pendingPurchases = $videoPurchases->whereNull('settled_at');
            $pendingCount = $pendingPurchases->count();
            $pendingGross = (float)$pendingPurchases->sum('price');

            $settledPurchases = $videoPurchases->whereNotNull('settled_at');
            $settledCount = $settledPurchases->count();
            $settledGross = (float)$settledPurchases->sum('price');
            $settledCreatorTotal = (float)$settledPurchases->sum('settled_creator_share');
            $settledAdminTotal = (float)$settledPurchases->sum('settled_admin_share');

            $suggestedAdminCut = round($pendingGross * ($commissionPercent / 100), 2);
            $suggestedCreatorShare = round($pendingGross * ((100 - $commissionPercent) / 100), 2);

            $status = 'no_sales';
            if ($pendingCount > 0) {
                $status = 'pending_settlement';
            } elseif ($totalSales > 0) {
                $status = 'settled';
            }

            $priceRupees = $v->price_minor_units !== null ? round($v->price_minor_units / 100, 2) : 0.00;

            return [
                'id' => (int)$v->id,
                'title' => $v->title,
                'type' => $v->type,
                'thumbnail_path' => $v->thumbnail_path,
                'duration_seconds' => $v->duration_seconds,
                'price_minor_units' => (int)($v->price_minor_units ?? 0),
                'price' => $priceRupees,
                'created_at' => $v->created_at,
                'creator' => [
                    'id' => (int)$v->creator_profile_id,
                    'user_id' => (int)$v->user_id,
                    'name' => $v->user_name ?? 'Unknown',
                    'email' => $v->user_email ?? '',
                    'channel_name' => $v->channel_name ?? $v->user_name ?? 'Creator',
                    'pending_earnings' => (float)($v->user_earnings ?? 0),
                    'withdrawable_balance' => (float)($v->user_actualearning ?? 0),
                ],
                'sales' => [
                    'total_count' => $totalSales,
                    'total_gross' => $totalGross,
                    'pending_count' => $pendingCount,
                    'pending_gross' => $pendingGross,
                    'settled_count' => $settledCount,
                    'settled_gross' => $settledGross,
                    'settled_creator_total' => $settledCreatorTotal,
                    'settled_admin_total' => $settledAdminTotal,
                ],
                'commission_percent' => $commissionPercent,
                'suggested_admin_cut' => $suggestedAdminCut,
                'suggested_creator_share' => $suggestedCreatorShare,
                'status' => $status,
            ];
        });

        if ($request->filled('status')) {
            $filterStatus = $request->input('status');
            $items = $items->filter(fn($i) => $i['status'] === $filterStatus)->values();
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'total_videos' => count($items),
                'total_pending_settlements' => $items->where('status', 'pending_settlement')->count(),
            ],
            'errors' => null,
        ]);
    }

    public function settleVideoSales(Request $request, $id)
    {
        $video = DB::table('videos')
            ->leftJoin('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->leftJoin('users', 'creator_profiles.user_id', '=', 'users.id')
            ->where('videos.id', $id)
            ->select('videos.*', 'creator_profiles.user_id', 'users.name as user_name', 'users.email as user_email')
            ->first();

        if (!$video) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']],
            ], 404);
        }

        if (!$video->user_id) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'BAD_REQUEST', 'message' => 'Video creator user not found']],
            ], 400);
        }

        $unsettled = DB::table('purchases')
            ->where('video_id', $id)
            ->whereNull('settled_at')
            ->get();

        if ($unsettled->isEmpty()) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NO_PENDING_SALES', 'message' => 'No pending sales found for this video']],
            ], 400);
        }

        $pendingGross = (float)$unsettled->sum('price');
        $commissionPercent = (float)(DB::table('settings')->where('group', 'payment')->where('key', 'platform_commission_percent')->value('value') ?? 20);

        if ($request->filled('creator_amount') && is_numeric($request->input('creator_amount'))) {
            $creatorAmount = max(0, round((float)$request->input('creator_amount'), 2));
        } else {
            $creatorAmount = round($pendingGross * ((100 - $commissionPercent) / 100), 2);
        }

        $adminCut = max(0, round($pendingGross - $creatorAmount, 2));

        DB::transaction(function () use ($id, $video, $unsettled, $pendingGross, $creatorAmount, $adminCut) {
            $user = DB::table('users')->where('id', $video->user_id)->lockForUpdate()->first();

            $perPurchaseCreator = count($unsettled) > 0 ? round($creatorAmount / count($unsettled), 2) : 0;
            $perPurchaseAdmin = count($unsettled) > 0 ? round($adminCut / count($unsettled), 2) : 0;

            // 1. Mark purchases as settled
            DB::table('purchases')
                ->where('video_id', $id)
                ->whereNull('settled_at')
                ->update([
                    'settled_at' => now(),
                    'settled_admin_share' => $perPurchaseAdmin,
                    'settled_creator_share' => $perPurchaseCreator,
                    'updated_at' => now(),
                ]);

            // 2. Credit creator's withdrawable balance (actualearning) and decrement pending earnings
            $currentActual = (float)($user->actualearning ?? 0);
            $currentEarnings = (float)($user->earnings ?? 0);

            $newActual = round($currentActual + $creatorAmount, 2);
            $newEarnings = max(0, round($currentEarnings - $pendingGross, 2));

            DB::table('users')->where('id', $video->user_id)->update([
                'actualearning' => $newActual,
                'earnings' => $newEarnings,
                'updated_at' => now(),
            ]);

            // 3. Add admin cut to admin_earnings
            if (Schema::hasTable('admin_earnings')) {
                $adminEarning = DB::table('admin_earnings')->first();
                if ($adminEarning) {
                    DB::table('admin_earnings')->where('id', $adminEarning->id)->update([
                        'revenue' => round(((float)$adminEarning->revenue) + $adminCut, 2),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('admin_earnings')->insert([
                        'revenue' => $adminCut,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 4. Record wallet transaction
            if (Schema::hasTable('wallets') && Schema::hasTable('wallet_transactions')) {
                $wallet = DB::table('wallets')->where('owner_id', $video->user_id)->where('type', 'user')->first();
                $walletId = $wallet ? $wallet->id : DB::table('wallets')->insertGetId([
                    'type' => 'user',
                    'owner_id' => $video->user_id,
                    'currency' => 'INR',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('wallet_transactions')->insert([
                    'wallet_id' => $walletId,
                    'type' => 'credit',
                    'category' => 'video_payout',
                    'amount_minor_units' => (int)round($creatorAmount * 100),
                    'status' => 'cleared',
                    'description' => "Earnings approved for video: {$video->title} (" . count($unsettled) . " student purchases)",
                    'created_by' => auth()->id() ?? 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 5. Notify creator (Confidential: No mention of admin cut!)
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->insert([
                    'id' => (string)\Illuminate\Support\Str::uuid(),
                    'type' => 'creator_earnings_approved',
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id' => $video->user_id,
                    'data' => json_encode([
                        'video_id' => $video->id,
                        'video_title' => $video->title,
                        'amount' => $creatorAmount,
                        'sales_count' => count($unsettled),
                        'message' => "Congratulations! ₹{$creatorAmount} in earnings for your video '{$video->title}' has been approved and added to your withdrawable balance.",
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'data' => [
                'video_id' => (int)$id,
                'sales_settled' => count($unsettled),
                'gross_amount' => $pendingGross,
                'admin_cut' => $adminCut,
                'creator_amount' => $creatorAmount,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
