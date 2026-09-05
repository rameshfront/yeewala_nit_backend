<?php

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EngagementController extends Controller
{
    /**
     * Get real reaction counts and current user's reaction for a video.
     */
    public function getReaction(Request $request, int $videoId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();

        $likeCount = DB::table('video_reactions')
            ->where('video_id', $videoId)
            ->where('type', 'like')
            ->count();

        $dislikeCount = DB::table('video_reactions')
            ->where('video_id', $videoId)
            ->where('type', 'dislike')
            ->count();

        $myReaction = $user
            ? DB::table('video_reactions')
                ->where('video_id', $videoId)
                ->where('user_id', $user->id)
                ->value('type')
            : null;

        return response()->json([
            'data' => [
                'video_id'      => $videoId,
                'like_count'    => (int)$likeCount,
                'dislike_count' => (int)$dislikeCount,
                'my_reaction'   => $myReaction,
            ],
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Set or toggle user reaction (like, dislike, or null to remove).
     */
    public function setReaction(Request $request, int $videoId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'data'   => null,
                'meta'   => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Please log in to react to videos.']],
            ], 401);
        }

        $validated = $request->validate([
            'type' => 'nullable|string|in:like,dislike',
        ]);

        $type = $validated['type'] ?? null;

        if ($type === null) {
            // Remove reaction
            DB::table('video_reactions')
                ->where('video_id', $videoId)
                ->where('user_id', $user->id)
                ->delete();
        } else {
            // Upsert reaction
            DB::table('video_reactions')->updateOrInsert(
                ['video_id' => $videoId, 'user_id' => $user->id],
                ['type' => $type, 'updated_at' => now()]
            );
        }

        // Compute real counts
        $likeCount = DB::table('video_reactions')
            ->where('video_id', $videoId)
            ->where('type', 'like')
            ->count();

        $dislikeCount = DB::table('video_reactions')
            ->where('video_id', $videoId)
            ->where('type', 'dislike')
            ->count();

        // Sync with videos table
        DB::table('videos')->where('id', $videoId)->update([
            'like_count'    => $likeCount,
            'dislike_count' => $dislikeCount,
        ]);

        return response()->json([
            'data' => [
                'video_id'      => $videoId,
                'like_count'    => (int)$likeCount,
                'dislike_count' => (int)$dislikeCount,
                'my_reaction'   => $type,
            ],
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * List top-level comments for a video.
     */
    public function listComments(Request $request, int $videoId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        $currentUserId = $user ? $user->id : 0;

        $comments = DB::table('comments')
            ->join('users', 'comments.user_id', '=', 'users.id')
            ->where('comments.video_id', $videoId)
            ->whereNull('comments.parent_id')
            ->whereNull('comments.deleted_at')
            ->where('comments.status', 'published')
            ->select(
                'comments.*',
                'users.name as user_name',
                'users.avatar_path as user_avatar'
            )
            ->orderBy('comments.is_pinned', 'desc')
            ->orderBy('comments.created_at', 'desc')
            ->limit(50)
            ->get();

        $formatted = $comments->map(function ($c) use ($currentUserId) {
            $repliesCount = DB::table('comments')
                ->where('parent_id', $c->id)
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->count();

            return [
                'id'            => (int)$c->id,
                'video_id'      => (int)$c->video_id,
                'parent_id'     => $c->parent_id ? (int)$c->parent_id : null,
                'user'          => [
                    'id'   => (int)$c->user_id,
                    'name' => $c->user_name,
                ],
                'body'          => $c->body,
                'status'        => $c->status,
                'is_pinned'     => (bool)$c->is_pinned,
                'is_hearted'    => (bool)$c->is_hearted,
                'hearted_at'    => $c->hearted_at ? Carbon::parse($c->hearted_at)->toISOString() : null,
                'edited_at'     => $c->edited_at ? Carbon::parse($c->edited_at)->toISOString() : null,
                'replies_count' => (int)$repliesCount,
                'is_mine'       => $currentUserId === (int)$c->user_id,
                'created_at'    => $c->created_at ? Carbon::parse($c->created_at)->toISOString() : now()->toISOString(),
                'updated_at'    => $c->updated_at ? Carbon::parse($c->updated_at)->toISOString() : now()->toISOString(),
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page'    => count($formatted),
                ],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Post a new comment or reply.
     */
    public function postComment(Request $request, int $videoId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json([
                'data'   => null,
                'meta'   => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Please log in to comment.']],
            ], 401);
        }

        $validated = $request->validate([
            'body'      => 'required|string|min:1|max:2000',
            'parent_id' => 'nullable|integer|exists:comments,id',
        ]);

        $commentId = DB::table('comments')->insertGetId([
            'video_id'   => $videoId,
            'user_id'    => $user->id,
            'parent_id'  => $validated['parent_id'] ?? null,
            'body'       => trim($validated['body']),
            'status'     => 'published',
            'is_pinned'  => false,
            'is_hearted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sync comment count in videos table
        $realCommentCount = DB::table('comments')
            ->where('video_id', $videoId)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->count();

        DB::table('videos')->where('id', $videoId)->update(['comment_count' => $realCommentCount]);

        return response()->json([
            'data' => [
                'id'            => (int)$commentId,
                'video_id'      => $videoId,
                'parent_id'     => isset($validated['parent_id']) ? (int)$validated['parent_id'] : null,
                'user'          => [
                    'id'   => (int)$user->id,
                    'name' => $user->name,
                ],
                'body'          => trim($validated['body']),
                'status'        => 'published',
                'is_pinned'     => false,
                'is_hearted'    => false,
                'hearted_at'    => null,
                'edited_at'     => null,
                'replies_count' => 0,
                'is_mine'       => true,
                'created_at'    => now()->toISOString(),
                'updated_at'    => now()->toISOString(),
            ],
            'meta'   => null,
            'errors' => null,
        ], 201);
    }

    /**
     * List replies for a specific parent comment.
     */
    public function listReplies(Request $request, int $commentId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        $currentUserId = $user ? $user->id : 0;

        $replies = DB::table('comments')
            ->join('users', 'comments.user_id', '=', 'users.id')
            ->where('comments.parent_id', $commentId)
            ->whereNull('comments.deleted_at')
            ->where('comments.status', 'published')
            ->select('comments.*', 'users.name as user_name', 'users.avatar_path as user_avatar')
            ->orderBy('comments.created_at', 'asc')
            ->get();

        $formatted = $replies->map(function ($c) use ($currentUserId) {
            return [
                'id'            => (int)$c->id,
                'video_id'      => (int)$c->video_id,
                'parent_id'     => (int)$c->parent_id,
                'user'          => [
                    'id'   => (int)$c->user_id,
                    'name' => $c->user_name,
                ],
                'body'          => $c->body,
                'status'        => $c->status,
                'is_pinned'     => (bool)$c->is_pinned,
                'is_hearted'    => (bool)$c->is_hearted,
                'hearted_at'    => $c->hearted_at ? Carbon::parse($c->hearted_at)->toISOString() : null,
                'edited_at'     => $c->edited_at ? Carbon::parse($c->edited_at)->toISOString() : null,
                'replies_count' => 0,
                'is_mine'       => $currentUserId === (int)$c->user_id,
                'created_at'    => $c->created_at ? Carbon::parse($c->created_at)->toISOString() : now()->toISOString(),
                'updated_at'    => $c->updated_at ? Carbon::parse($c->updated_at)->toISOString() : now()->toISOString(),
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page'    => count($formatted),
                ],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Update comment text.
     */
    public function updateComment(Request $request, int $commentId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $comment = DB::table('comments')->where('id', $commentId)->whereNull('deleted_at')->first();
        if (!$comment || (int)$comment->user_id !== (int)$user->id) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'FORBIDDEN', 'message' => 'You cannot edit this comment.']]], 403);
        }

        $validated = $request->validate(['body' => 'required|string|min:1|max:2000']);

        DB::table('comments')->where('id', $commentId)->update([
            'body'      => trim($validated['body']),
            'edited_at' => now(),
            'updated_at'=> now(),
        ]);

        return response()->json([
            'data' => [
                'id'         => (int)$comment->id,
                'video_id'   => (int)$comment->video_id,
                'parent_id'  => $comment->parent_id ? (int)$comment->parent_id : null,
                'user'       => ['id' => (int)$user->id, 'name' => $user->name],
                'body'       => trim($validated['body']),
                'status'     => $comment->status,
                'is_pinned'  => (bool)$comment->is_pinned,
                'is_hearted' => (bool)$comment->is_hearted,
                'hearted_at' => $comment->hearted_at ? Carbon::parse($comment->hearted_at)->toISOString() : null,
                'edited_at'  => now()->toISOString(),
                'is_mine'    => true,
                'created_at' => $comment->created_at ? Carbon::parse($comment->created_at)->toISOString() : now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ],
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * Delete comment.
     */
    public function deleteComment(Request $request, int $commentId): JsonResponse
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $comment = DB::table('comments')->where('id', $commentId)->first();
        if (!$comment) {
            return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
        }

        if ((int)$comment->user_id !== (int)$user->id) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Permission denied.']]], 403);
        }

        DB::table('comments')->where('id', $commentId)->update(['deleted_at' => now()]);

        $realCount = DB::table('comments')
            ->where('video_id', $comment->video_id)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->count();

        DB::table('videos')->where('id', $comment->video_id)->update(['comment_count' => $realCount]);

        return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
    }

    /**
     * Heart or unheart comment (creator only).
     */
    public function heartComment(Request $request, int $commentId): JsonResponse
    {
        $comment = DB::table('comments')->where('id', $commentId)->first();
        if (!$comment) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Comment not found.']]], 404);
        }

        $isHearted = !(bool)$comment->is_hearted;
        DB::table('comments')->where('id', $commentId)->update([
            'is_hearted' => $isHearted,
            'hearted_at' => $isHearted ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
    }

    /**
     * Pin or unpin comment (creator only).
     */
    public function pinComment(Request $request, int $commentId): JsonResponse
    {
        $comment = DB::table('comments')->where('id', $commentId)->first();
        if (!$comment) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Comment not found.']]], 404);
        }

        $isPinned = !(bool)$comment->is_pinned;
        DB::table('comments')->where('id', $commentId)->update([
            'is_pinned'  => $isPinned,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
    }
}
