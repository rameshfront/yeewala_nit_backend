<?php

namespace App\Http\Controllers\Api\V1\Monetization;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RazorpayWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from Razorpay.
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $secret = config('services.razorpay.webhook_secret')
            ?: env('RAZORPAY_WEBHOOK_SECRET')
            ?: config('services.razorpay.key_secret')
            ?: env('RAZORPAY_KEY_SECRET', 'ADVWusim1YO3hLvbKY8QyfVB');

        // Verify webhook signature if provided
        if ($signature && $secret) {
            $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);
            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('Razorpay webhook signature verification failed.');
                return response()->json(['status' => 'invalid_signature'], 400);
            }
        }

        $data = json_decode($rawPayload, true);
        if (!$data || !isset($data['event'])) {
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $event = $data['event'];
        Log::info("Razorpay webhook received: {$event}");

        if (in_array($event, ['payment.captured', 'order.paid'])) {
            $payment = $data['payload']['payment']['entity'] ?? [];
            $paymentId = $payment['id'] ?? null;
            $orderId = $payment['order_id'] ?? null;

            if ($orderId || $paymentId) {
                // Find matching recharge request
                $recharge = DB::table('recharge_requests')
                    ->where(function ($q) use ($orderId, $paymentId) {
                        if ($orderId) {
                            $q->where('transaction_reference', $orderId);
                        }
                        if ($paymentId) {
                            $q->orWhere('transaction_reference', $paymentId);
                        }
                    })
                    ->first();

                if ($recharge && $recharge->status === 'pending') {
                    // Update transaction reference to actual payment ID
                    DB::table('recharge_requests')
                        ->where('id', $recharge->id)
                        ->update([
                            'transaction_reference' => $paymentId ?: $recharge->transaction_reference,
                            'updated_at'            => now(),
                        ]);

                    // Notify admins if not already notified
                    if (Schema::hasTable('notifications')) {
                        $adminIds = DB::table('model_has_roles')
                            ->where('role_id', 5)
                            ->pluck('model_id')
                            ->toArray();

                        if (empty($adminIds)) {
                            $adminIds = [1, 3];
                        }

                        $user = DB::table('users')->where('id', $recharge->user_id)->first();
                        $userName = $user->name ?? 'User';

                        foreach ($adminIds as $adminId) {
                            $alreadyNotified = DB::table('notifications')
                                ->where('notifiable_id', $adminId)
                                ->where('notifiable_type', 'App\\Models\\User')
                                ->where('data', 'like', '%"recharge_id":' . $recharge->id . '%')
                                ->exists();

                            if (!$alreadyNotified) {
                                DB::table('notifications')->insert([
                                    'id'              => (string)Str::uuid(),
                                    'type'            => 'wallet_topup_requested',
                                    'notifiable_type' => 'App\\Models\\User',
                                    'notifiable_id'   => $adminId,
                                    'data'            => json_encode([
                                        'type'        => 'wallet_topup_requested',
                                        'amount'      => (float)$recharge->requested_amount,
                                        'user_name'   => $userName,
                                        'user_id'     => (int)$recharge->user_id,
                                        'recharge_id' => (int)$recharge->id,
                                        'message'     => "New wallet top-up request: ₹{$recharge->requested_amount} from {$userName}",
                                    ]),
                                    'read_at'         => null,
                                    'created_at'      => now(),
                                    'updated_at'      => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
