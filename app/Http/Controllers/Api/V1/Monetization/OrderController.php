<?php

namespace App\Http\Controllers\Api\V1\Monetization;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    /**
     * List current user's purchase orders.
     */
    public function index(Request $request)
    {
        $user = auth('sanctum')->user() ?? auth('web')->user() ?? Auth::user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        // Sync any past video_purchases into orders table if missing
        if (Schema::hasTable('video_purchases') && Schema::hasTable('orders')) {
            $purchasedVideos = DB::table('video_purchases')->where('user_id', $user->id)->get();
            foreach ($purchasedVideos as $vp) {
                $exists = DB::table('orders')
                    ->where('user_id', $user->id)
                    ->where('orderable_type', 'video')
                    ->where('orderable_id', $vp->video_id)
                    ->exists();

                if (!$exists) {
                    DB::table('orders')->insert([
                        'order_number' => 'ORD-VP-' . str_pad($vp->id, 6, '0', STR_PAD_LEFT),
                        'user_id' => $user->id,
                        'orderable_type' => 'video',
                        'orderable_id' => $vp->video_id,
                        'amount_minor_units' => $vp->price_minor_units ?? 0,
                        'discount_minor_units' => 0,
                        'total_minor_units' => $vp->price_minor_units ?? 0,
                        'currency' => $vp->currency ?? 'INR',
                        'status' => 'paid',
                        'gateway' => 'wallet',
                        'paid_at' => $vp->purchased_at ?? $vp->created_at ?? now(),
                        'created_at' => $vp->created_at ?? now(),
                        'updated_at' => $vp->updated_at ?? now(),
                    ]);
                }
            }
        }

        $query = DB::table('orders')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        $orders = $query->get();

        $formatted = $orders->map(function ($order) {
            return $this->formatOrder($order);
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

    /**
     * Get single order details.
     */
    public function show($id)
    {
        $user = auth('sanctum')->user() ?? auth('web')->user() ?? Auth::user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        $order = DB::table('orders')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Order not found']],
            ], 404);
        }

        return response()->json([
            'data' => $this->formatOrder($order),
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Get order status.
     */
    public function status($id)
    {
        return $this->show($id);
    }

    /**
     * Format raw order database row into API envelope format.
     */
    private function formatOrder($order): array
    {
        $orderableSummary = null;

        if ($order->orderable_type === 'video') {
            $video = DB::table('videos')->where('id', $order->orderable_id)->first();
            $orderableSummary = [
                'type' => 'video',
                'id' => (int)$order->orderable_id,
                'title' => $video ? $video->title : ('Video #' . $order->orderable_id),
            ];
        } elseif ($order->orderable_type === 'membership_plan') {
            $plan = DB::table('membership_plans')->where('id', $order->orderable_id)->first();
            $orderableSummary = [
                'type' => 'membership_plan',
                'id' => (int)$order->orderable_id,
                'name' => $plan ? $plan->name : ('Plan #' . $order->orderable_id),
            ];
        }

        return [
            'id' => (int)$order->id,
            'order_number' => $order->order_number,
            'orderable_type' => $order->orderable_type,
            'orderable_id' => (int)$order->orderable_id,
            'orderable' => $orderableSummary,
            'amount_minor_units' => (int)$order->amount_minor_units,
            'discount_minor_units' => (int)($order->discount_minor_units ?? 0),
            'total_minor_units' => (int)$order->total_minor_units,
            'currency' => $order->currency ?? 'INR',
            'status' => $order->status ?? 'paid',
            'gateway' => $order->gateway ?? 'wallet',
            'gateway_order_id' => $order->gateway_order_id,
            'paid_at' => $order->paid_at ? Carbon::parse($order->paid_at)->toISOString() : null,
            'refunded_at' => $order->refunded_at ? Carbon::parse($order->refunded_at)->toISOString() : null,
            'refund_reason' => $order->refund_reason,
            'invoice' => null,
            'created_at' => $order->created_at ? Carbon::parse($order->created_at)->toISOString() : now()->toISOString(),
        ];
    }
}
