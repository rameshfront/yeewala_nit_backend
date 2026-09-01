<?php

namespace App\Http\Controllers\Api\V1\Monetization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\ApproveEarningsRequest;
use App\Http\Requests\Wallet\ApproveRechargeRequest;
use App\Http\Requests\Wallet\PurchaseVideosRequest;
use App\Http\Requests\Wallet\WithdrawalRequest;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletOperationController extends Controller
{
    public function __construct(
        protected WalletService $walletService
    ) {}

    /**
     * Get user's current wallet fields (amount, earnings, actualearning).
     */
    public function getWalletBalances(Request $request): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $fresh = DB::table('users')->where('id', $user->id)->first();

        return response()->json([
            'data' => [
                'user_id'       => (int)$fresh->id,
                'amount'        => $this->walletService->toDecimal($fresh->amount ?? '0.00'),
                'earnings'      => $this->walletService->toDecimal($fresh->earnings ?? '0.00'),
                'actualearning' => $this->walletService->toDecimal($fresh->actualearning ?? '0.00'),
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Logic 1: Purchase one or more videos.
     * Deducts buyer's amount and credits uploader's pending earnings (awaiting admin approval).
     */
    public function purchaseVideos(PurchaseVideosRequest $request): JsonResponse
    {
        $buyerId = Auth::id() ?? auth('sanctum')->id();
        $videoIds = $request->validated('video_ids');

        $result = $this->walletService->purchaseVideos($buyerId, $videoIds);

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Logic 2: Admin approves uploader's pending earnings -> moves to actualearning.
     */
    public function approveEarnings(ApproveEarningsRequest $request): JsonResponse
    {
        $userId = (int)$request->validated('user_id');
        $amount = (string)$request->validated('amount');

        $result = $this->walletService->approveEarnings($userId, $amount);

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Logic 3: User requests a withdrawal from actualearning.
     */
    public function requestWithdrawal(WithdrawalRequest $request): JsonResponse
    {
        $userId = Auth::id() ?? auth('sanctum')->id();
        $amount = (string)$request->validated('amount');
        $bankInfo = $request->input('bank_info');

        $result = $this->walletService->requestWithdrawal($userId, $amount, ['bank_info' => $bankInfo]);

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin approves a withdrawal request.
     */
    public function approveWithdrawal(Request $request, $id): JsonResponse
    {
        $result = $this->walletService->approveWithdrawal((int)$id, $request->input('reference'));

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Admin rejects a withdrawal request -> refund amount back to actualearning.
     */
    public function rejectWithdrawal(Request $request, $id): JsonResponse
    {
        $result = $this->walletService->rejectWithdrawal((int)$id, $request->input('reason'));

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Logic 4: Admin approves wallet recharge.
     */
    public function approveRecharge(ApproveRechargeRequest $request): JsonResponse
    {
        $userId          = (int)$request->validated('user_id');
        $requestedAmount = (string)$request->validated('requested_amount');
        $approvedAmount  = (string)$request->validated('approved_amount');
        $rechargeId      = $request->validated('recharge_id') ? (int)$request->validated('recharge_id') : null;

        $result = $this->walletService->approveRecharge($userId, $requestedAmount, $approvedAmount, $rechargeId);

        return response()->json([
            'data'   => $result,
            'meta'   => null,
            'errors' => null,
        ]);
    }
}
