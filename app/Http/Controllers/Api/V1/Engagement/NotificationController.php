<?php

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        if (!Schema::hasTable('notifications')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'pagination' => ['next_cursor' => null, 'per_page' => 0],
                    'unread_count' => 0,
                ],
                'errors' => null,
            ]);
        }

        $query = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('notifiable_type', 'App\\Models\\User');

        $unreadCount = (clone $query)->whereNull('read_at')->count();

        $notifications = $query->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $formatted = $notifications->map(function ($n) {
            $data = is_string($n->data) ? json_decode($n->data, true) : (array)$n->data;
            if (!is_array($data)) {
                $data = [];
            }
            $type = $data['type'] ?? $n->type;

            return [
                'id' => (string)$n->id,
                'type' => $type,
                'data' => $data,
                'read_at' => $n->read_at ? Carbon::parse($n->read_at)->toIso8601String() : null,
                'created_at' => Carbon::parse($n->created_at)->toIso8601String(),
            ];
        })->values()->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ],
                'unread_count' => $unreadCount,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')
                ->where('id', $id)
                ->where('notifiable_id', $user->id)
                ->where('notifiable_type', 'App\\Models\\User')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'data' => null,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')
                ->where('notifiable_id', $user->id)
                ->where('notifiable_type', 'App\\Models\\User')
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'data' => null,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Get user notification preferences.
     */
    public function preferences(Request $request): JsonResponse
    {
        $defaults = [
            'new_follower' => ['database' => true, 'mail' => false],
            'new_comment' => ['database' => true, 'mail' => false],
            'comment_reply' => ['database' => true, 'mail' => false],
            'video_published' => ['database' => true, 'mail' => false],
            'playlist_updated' => ['database' => true, 'mail' => false],
            'wallet_topup' => ['database' => true, 'mail' => true],
        ];

        return response()->json([
            'data' => $defaults,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Update user notification preferences.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        return $this->preferences($request);
    }
}
