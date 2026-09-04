<?php

namespace App\Services\Wallet;

use App\Exceptions\Wallet\InsufficientBalanceException;
use App\Exceptions\Wallet\InsufficientEarningsException;
use App\Exceptions\Wallet\InsufficientWithdrawableBalanceException;
use App\Exceptions\Wallet\InvalidAmountException;
use App\Exceptions\Wallet\VideoAlreadyPurchasedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalletService
{
    /**
     * Helper to format numbers strictly as DECIMAL(10,2) string.
     */
    public function toDecimal(mixed $value): string
    {
        return number_format((float)$value, 2, '.', '');
    }

    // =========================================================================
    // LOGIC 1: VIDEO PURCHASE — DEDUCT BUYER, CREDIT UPLOADER EARNINGS
    // =========================================================================
    /**
     * Purchase one or more videos.
     * Deducts buyer's amount and credits uploader's pending earnings (awaiting admin approval).
     *
     * @param int   $buyerId
     * @param int[] $videoIds
     * @return array
     *
     * @throws InsufficientBalanceException
     * @throws VideoAlreadyPurchasedException
     * @throws \Throwable
     */
    public function purchaseVideos(int $buyerId, array $videoIds): array
    {
        return DB::transaction(function () use ($buyerId, $videoIds) {
            // 1. Fetch buyer's current amount with row lock
            $buyer = DB::table('users')
                ->where('id', $buyerId)
                ->lockForUpdate()
                ->first();

            if (!$buyer) {
                throw new \InvalidArgumentException("Buyer user #{$buyerId} not found.");
            }

            $buyerAmount = $this->toDecimal($buyer->amount ?? '0.00');

            // 2. Fetch video details from DB (Never trust request payload)
            $videoRecords = [];
            $total = '0.00';

            $hasPrice = Schema::hasColumn('videos', 'price');
            $hasOwnerId = Schema::hasColumn('videos', 'owner_id');

            foreach ($videoIds as $videoId) {
                $selects = ['videos.id', 'videos.creator_profile_id'];
                if ($hasPrice) {
                    $selects[] = DB::raw('COALESCE(videos.price, videos.price_minor_units / 100.0, 0.00) as video_price');
                } else {
                    $selects[] = DB::raw('COALESCE(videos.price_minor_units / 100.0, 0.00) as video_price');
                }

                if ($hasOwnerId) {
                    $selects[] = DB::raw('COALESCE(videos.owner_id, creator_profiles.user_id) as owner_user_id');
                } else {
                    $selects[] = DB::raw('creator_profiles.user_id as owner_user_id');
                }

                $video = DB::table('videos')
                    ->leftJoin('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
                    ->where('videos.id', $videoId)
                    ->whereNull('videos.deleted_at')
                    ->select($selects)
                    ->first();

                if (!$video) {
                    throw new \InvalidArgumentException("Video #{$videoId} was not found or has been removed.");
                }

                $price = $this->toDecimal($video->video_price);
                $ownerId = (int)($video->owner_user_id ?? 0);

                // 3. Check buyer hasn't already purchased this video
                $alreadyPurchased = DB::table('purchases')
                    ->where('buyer_id', $buyerId)
                    ->where('video_id', $videoId)
                    ->exists();

                if (!$alreadyPurchased && Schema::hasTable('video_purchases')) {
                    $alreadyPurchased = DB::table('video_purchases')
                        ->where('user_id', $buyerId)
                        ->where('video_id', $videoId)
                        ->exists();
                }

                if ($alreadyPurchased) {
                    throw new VideoAlreadyPurchasedException((int)$videoId);
                }

                $videoRecords[] = [
                    'video_id' => (int)$videoId,
                    'price'    => $price,
                    'owner_id' => $ownerId,
                ];

                // 4. total = sum of all fetched prices
                $total = bcadd($total, $price, 2);
            }

            // 5. If total > buyer.amount -> abort transaction, throw InsufficientBalanceException
            if (bccomp($total, $buyerAmount, 2) === 1) {
                throw new InsufficientBalanceException(
                    "Insufficient balance. Total required: ₹{$total}, Available in wallet: ₹{$buyerAmount}"
                );
            }

            // 6. Deduct buyer: buyer.amount = buyer.amount - total
            $newBuyerAmount = bcsub($buyerAmount, $total, 2);
            DB::table('users')->where('id', $buyerId)->update([
                'amount'     => $newBuyerAmount,
                'updated_at' => now(),
            ]);

            $now = now();
            foreach ($videoRecords as $record) {
                // Credit uploader's pending earnings (Admin will approve later):
                // owner.earnings = owner.earnings + video.price
                if ($record['owner_id'] && $record['owner_id'] !== $buyerId) {
                    $owner = DB::table('users')
                        ->where('id', $record['owner_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($owner) {
                        $ownerEarnings = $this->toDecimal($owner->earnings ?? '0.00');
                        $newEarnings = bcadd($ownerEarnings, $record['price'], 2);

                        DB::table('users')->where('id', $record['owner_id'])->update([
                            'earnings'   => $newEarnings,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Insert purchase record (buyer_id, video_id, price)
                if (Schema::hasTable('purchases')) {
                    DB::table('purchases')->insert([
                        'buyer_id'   => $buyerId,
                        'video_id'   => $record['video_id'],
                        'price'      => $record['price'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                if (Schema::hasTable('video_purchases')) {
                    DB::table('video_purchases')->insert([
                        'user_id'           => $buyerId,
                        'video_id'          => $record['video_id'],
                        'price_minor_units' => (int)bcmul($record['price'], '100', 0),
                        'currency'          => 'INR',
                        'purchased_at'      => $now,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]);
                }

                if (Schema::hasTable('orders')) {
                    $priceMinor = (int)bcmul($record['price'], '100', 0);
                    DB::table('orders')->insert([
                        'order_number'        => 'ORD-VP-' . strtoupper(uniqid()),
                        'user_id'             => $buyerId,
                        'orderable_type'      => 'video',
                        'orderable_id'        => $record['video_id'],
                        'amount_minor_units'  => $priceMinor,
                        'discount_minor_units'=> 0,
                        'total_minor_units'   => $priceMinor,
                        'currency'            => 'INR',
                        'status'              => 'paid',
                        'gateway'             => 'wallet',
                        'paid_at'             => $now,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                }

                // Insert into saved_videos table
                if (Schema::hasTable('saved_videos')) {
                    DB::table('saved_videos')->updateOrInsert(
                        ['user_id' => $buyerId, 'video_id' => $record['video_id']],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }
            }

            // Also keep wallets table in sync if present
            if (Schema::hasTable('wallets') && Schema::hasTable('wallet_transactions')) {
                $wallet = DB::table('wallets')->where('owner_id', $buyerId)->where('type', 'user')->first();
                if ($wallet) {
                    DB::table('wallet_transactions')->insert([
                        'wallet_id'          => $wallet->id,
                        'type'               => 'debit',
                        'category'           => 'purchase',
                        'amount_minor_units' => (int)bcmul($total, '100', 0),
                        'status'             => 'cleared',
                        'description'        => 'Purchase of videos: ' . implode(', ', $videoIds),
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                }
            }

            // 7. Commit transaction and return buyer's new amount
            return [
                'success'           => true,
                'total_deducted'    => $total,
                'buyer_id'          => $buyerId,
                'amount'            => $newBuyerAmount,
                'purchased_count'   => count($videoRecords),
            ];
        });
    }

    // =========================================================================
    // LOGIC 2: ADMIN APPROVES EARNINGS -> MOVES TO WITHDRAWABLE BALANCE
    // =========================================================================
    /**
     * Admin approves an uploader's pending earnings.
     * user.earnings = user.earnings - amount
     * user.actualearning = user.actualearning + amount
     *
     * @param int          $userId
     * @param string|float $amountToApprove
     * @return array
     *
     * @throws InsufficientEarningsException
     * @throws InvalidAmountException
     */
    public function approveEarnings(int $userId, string|float $amountToApprove): array
    {
        $amount = $this->toDecimal($amountToApprove);

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidAmountException("Approval amount must be greater than zero.");
        }

        return DB::transaction(function () use ($userId, $amount) {
            // 2. Fetch user's current earnings with row lock
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \InvalidArgumentException("User #{$userId} not found.");
            }

            $currentEarnings = $this->toDecimal($user->earnings ?? '0.00');
            $currentActual   = $this->toDecimal($user->actualearning ?? '0.00');

            // 3. If amount > user.earnings -> reject with error
            if (bccomp($amount, $currentEarnings, 2) === 1) {
                throw new InsufficientEarningsException(
                    "Approval amount (₹{$amount}) exceeds pending earnings (₹{$currentEarnings})."
                );
            }

            // 4. Move from earnings to actualearning
            $newEarnings = bcsub($currentEarnings, $amount, 2);
            $newActual   = bcadd($currentActual, $amount, 2);

            DB::table('users')->where('id', $userId)->update([
                'earnings'      => $newEarnings,
                'actualearning' => $newActual,
                'updated_at'    => now(),
            ]);

            // 5. Commit and return updated earnings + actualearning
            return [
                'success'          => true,
                'user_id'          => $userId,
                'approved_amount'  => $amount,
                'earnings'         => $newEarnings,
                'actualearning'    => $newActual,
            ];
        });
    }

    // =========================================================================
    // LOGIC 3: USER WITHDRAWAL REQUEST -> DEDUCT FROM WITHDRAWABLE BALANCE
    // =========================================================================
    /**
     * User requests a withdrawal, deducted from actualearning and marked pending for admin.
     *
     * @param int          $userId
     * @param string|float $withdrawAmount
     * @param array        $extraData
     * @return array
     *
     * @throws InvalidAmountException
     * @throws InsufficientWithdrawableBalanceException
     */
    public function requestWithdrawal(int $userId, string|float $withdrawAmount, array $extraData = []): array
    {
        $amount = $this->toDecimal($withdrawAmount);

        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidAmountException("Withdrawal amount must be greater than zero.");
        }

        return DB::transaction(function () use ($userId, $amount, $extraData) {
            // 2. Fetch user's current actualearning with row lock
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \InvalidArgumentException("User #{$userId} not found.");
            }

            $currentActual = $this->toDecimal($user->actualearning ?? '0.00');

            // 3. Specific validations: "no balance" vs "amount exceeds available balance"
            if (bccomp($currentActual, '0.00', 2) <= 0) {
                throw new InsufficientWithdrawableBalanceException("No balance available for withdrawal.");
            }

            if (bccomp($amount, $currentActual, 2) === 1) {
                throw new InsufficientWithdrawableBalanceException(
                    "Withdrawal amount (₹{$amount}) exceeds available withdrawable balance (₹{$currentActual})."
                );
            }

            // 4. Inside DB transaction:
            //    insert withdraw request record (user_id, amount, status = pending)
            //    user.actualearning = user.actualearning - amount
            $requestId = null;
            $now = now();

            if (Schema::hasTable('withdraw_requests')) {
                $requestId = DB::table('withdraw_requests')->insertGetId([
                    'user_id'    => $userId,
                    'amount'     => $amount,
                    'status'     => 'pending',
                    'bank_info'  => $extraData['bank_info'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $newActual = bcsub($currentActual, $amount, 2);
            DB::table('users')->where('id', $userId)->update([
                'actualearning' => $newActual,
                'updated_at'    => $now,
            ]);

            // 5. Commit and return new actualearning balance
            return [
                'success'          => true,
                'withdrawal_id'    => $requestId,
                'requested_amount' => $amount,
                'actualearning'    => $newActual,
                'status'           => 'pending',
            ];
        });
    }

    /**
     * Admin approves a withdrawal request.
     */
    public function approveWithdrawal(int $withdrawalId, ?string $reference = null): array
    {
        return DB::transaction(function () use ($withdrawalId, $reference) {
            $withdraw = DB::table('withdraw_requests')->where('id', $withdrawalId)->lockForUpdate()->first();
            if (!$withdraw) {
                throw new \InvalidArgumentException("Withdrawal request #{$withdrawalId} not found.");
            }

            if ($withdraw->status !== 'pending') {
                throw new \InvalidArgumentException("Withdrawal request is already {$withdraw->status}.");
            }

            DB::table('withdraw_requests')->where('id', $withdrawalId)->update([
                'status'     => 'approved',
                'updated_at' => now(),
            ]);

            return [
                'success'       => true,
                'withdrawal_id' => $withdrawalId,
                'status'        => 'approved',
            ];
        });
    }

    /**
     * Admin rejects a withdrawal request -> refund amount back to actualearning!
     */
    public function rejectWithdrawal(int $withdrawalId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($withdrawalId, $reason) {
            $withdraw = DB::table('withdraw_requests')->where('id', $withdrawalId)->lockForUpdate()->first();
            if (!$withdraw) {
                throw new \InvalidArgumentException("Withdrawal request #{$withdrawalId} not found.");
            }

            if ($withdraw->status !== 'pending') {
                throw new \InvalidArgumentException("Withdrawal request is already {$withdraw->status}.");
            }

            $user = DB::table('users')->where('id', $withdraw->user_id)->lockForUpdate()->first();
            $refundAmount = $this->toDecimal($withdraw->amount);
            $newActual = bcadd($this->toDecimal($user->actualearning ?? '0.00'), $refundAmount, 2);

            // Refund back to user's actualearning
            DB::table('users')->where('id', $user->id)->update([
                'actualearning' => $newActual,
                'updated_at'    => now(),
            ]);

            DB::table('withdraw_requests')->where('id', $withdrawalId)->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason ?? 'Rejected by admin',
                'updated_at'       => now(),
            ]);

            return [
                'success'          => true,
                'withdrawal_id'    => $withdrawalId,
                'status'           => 'rejected',
                'refunded_amount'  => $refundAmount,
                'actualearning'    => $newActual,
            ];
        });
    }

    // =========================================================================
    // LOGIC 4: WALLET RECHARGE APPROVAL — CREDIT BUYER, TRACK PLATFORM DIFF
    // =========================================================================
    /**
     * Admin approves a user's recharge request, crediting buyer amount and platform difference.
     *
     * @param int          $userId
     * @param string|float $requestedAmount
     * @param string|float $approvedAmount
     * @param int|null     $rechargeId
     * @return array
     */
    public function approveRecharge(
        int $userId,
        string|float $requestedAmount,
        string|float $approvedAmount,
        ?int $rechargeId = null
    ): array {
        $reqAmount = $this->toDecimal($requestedAmount);
        $appAmount = $this->toDecimal($approvedAmount);

        return DB::transaction(function () use ($userId, $reqAmount, $appAmount, $rechargeId) {
            // 2. Fetch user's current amount with row lock
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \InvalidArgumentException("User #{$userId} not found.");
            }

            $currentAmount = $this->toDecimal($user->amount ?? '0.00');

            // 3. difference = requested_amount - approved_amount
            $difference = bcsub($reqAmount, $appAmount, 2);

            // 4. Inside DB transaction:
            //    user.amount = user.amount + approved_amount
            $newAmount = bcadd($currentAmount, $appAmount, 2);

            DB::table('users')->where('id', $userId)->update([
                'amount'     => $newAmount,
                'updated_at' => now(),
            ]);

            // admin_earnings.revenue = admin_earnings.revenue + difference
            if (Schema::hasTable('admin_earnings')) {
                $existingAdmin = DB::table('admin_earnings')->first();
                if ($existingAdmin) {
                    $adminRev = $this->toDecimal($existingAdmin->revenue ?? '0.00');
                    $newAdminRev = bcadd($adminRev, $difference, 2);

                    DB::table('admin_earnings')->where('id', $existingAdmin->id)->update([
                        'revenue'    => $newAdminRev,
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('admin_earnings')->insert([
                        'revenue'    => $difference,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // update recharge request status = approved, store approved_amount
            if ($rechargeId && Schema::hasTable('recharge_requests')) {
                DB::table('recharge_requests')->where('id', $rechargeId)->update([
                    'status'          => 'approved',
                    'approved_amount' => $appAmount,
                    'updated_at'      => now(),
                ]);
            }

            // 5. Commit and return updated buyer wallet amount
            return [
                'success'          => true,
                'user_id'          => $userId,
                'requested_amount' => $reqAmount,
                'approved_amount'  => $appAmount,
                'difference'       => $difference,
                'amount'           => $newAmount,
            ];
        });
    }
}
