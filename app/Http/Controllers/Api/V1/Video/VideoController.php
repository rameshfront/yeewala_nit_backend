<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    public function formatVideo($v, $myReaction = null)
    {
        $avatarUrl = $v->avatar_path ?? null;
        if ($avatarUrl && !str_starts_with($avatarUrl, 'http://') && !str_starts_with($avatarUrl, 'https://')) {
            $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
            if (!str_starts_with($avatarUrl, '/storage/') && !str_starts_with($avatarUrl, 'storage/')) {
                $avatarUrl = '/storage/' . ltrim($avatarUrl, '/');
            }
            $avatarUrl = $baseUrl . '/' . ltrim($avatarUrl, '/');
        }

        if ($myReaction === null) {
            $user = auth('sanctum')->user() ?? auth('web')->user();
            if ($user && isset($v->id)) {
                $myReaction = DB::table('video_reactions')
                    ->where('video_id', $v->id)
                    ->where('user_id', $user->id)
                    ->value('type');
            }
        }

        return [
            'id' => (int)$v->id,
            'creator_profile_id' => (int)$v->creator_profile_id,
            'creator' => [
                'id' => (int)$v->creator_profile_id,
                'user_id' => (int)$v->user_id,
                'channel_name' => $v->channel_name ?? 'Ramesh K Channel',
                'channel_slug' => $v->channel_slug ?? 'ramesh-k',
                'avatar_url' => $avatarUrl ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400&auto=format&fit=crop',
                'is_verified_badge' => (bool)($v->is_verified_badge ?? 1),
            ],
            'title' => $v->title,
            'description' => $v->description,
            'type' => $v->type,
            'visibility' => $v->visibility ?? 'public',
            'status' => $v->status ?? 'ready',
            'review_status' => $v->review_status ?? 'approved',
            'review_notes' => $v->review_notes ?? null,
            'reviewed_by' => null,
            'reviewed_at' => $v->reviewed_at,
            'is_hidden' => false,
            'is_featured' => (bool)$v->is_featured,
            'category' => null,
            'tags' => [],
            'renditions' => [],
            'captions' => [],
            'duration_seconds' => (int)($v->duration_seconds ?? 0),
            'source_width' => (int)($v->source_width ?? 1920),
            'source_height' => (int)($v->source_height ?? 1080),
            'price_minor_units' => $v->price_minor_units ? (int)$v->price_minor_units : null,
            'view_count' => (int)($v->view_count ?? 0),
            'like_count' => (int)($v->like_count ?? 0),
            'dislike_count' => (int)($v->dislike_count ?? 0),
            'comment_count' => (int)($v->comment_count ?? 0),
            'my_reaction' => $myReaction,
            'scheduled_at' => null,
            'published_at' => $v->published_at,
            'thumbnail_url' => $v->thumbnail_path,
            'sprite_url' => null,
            'manifest_url' => (isset($v->source_path) && (str_starts_with($v->source_path, 'http://') || str_starts_with($v->source_path, 'https://')))
                ? $v->source_path
                : 'https://www.w3schools.com/html/mov_bbb.mp4',
            'created_at' => $v->created_at,
            'updated_at' => $v->updated_at,
        ];
    }

    public function index(Request $request)
    {
        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->whereNull('videos.deleted_at');

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        $videos = $query->orderBy('videos.created_at', 'desc')->get();

        $formatted = $videos->map(fn($v) => $this->formatVideo($v))->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ]
            ],
            'errors' => null,
        ]);
    }

    public function myVideos(Request $request)
    {
        $user = auth('sanctum')->user() ?? auth('web')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $creatorProfile = DB::table('creator_profiles')->where('user_id', $user->id)->first();
        if (!$creatorProfile) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 0]],
                'errors' => null,
            ]);
        }

        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.creator_profile_id', $creatorProfile->id)
            ->whereNull('videos.deleted_at');

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        if ($request->filled('status')) {
            $query->where('videos.status', $request->query('status'));
        }

        if ($request->filled('review_status')) {
            $query->where('videos.review_status', $request->query('review_status'));
        }

        $videos = $query->orderBy('videos.created_at', 'desc')->get();
        $formatted = $videos->map(fn($v) => $this->formatVideo($v))->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => [
                'pagination' => [
                    'next_cursor' => null,
                    'per_page' => count($formatted),
                ]
            ],
            'errors' => null,
        ]);
    }

    public function show($id)
    {
        $v = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.id', $id)
            ->first();

        if (!$v) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']]], 404);
        }

        $user = auth('sanctum')->user() ?? auth('web')->user();
        $isPaid = $v->visibility === 'paid' || ((int)($v->price_minor_units ?? 0)) > 0;
        $isOwner = $user && $user->id === (int)$v->user_id;

        if ($isPaid && !$isOwner) {
            $isPurchased = false;
            if ($user) {
                $isPurchased = DB::table('video_purchases')
                    ->where('user_id', $user->id)
                    ->where('video_id', $v->id)
                    ->exists();
            }

            if (!$isPurchased) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'video_preview' => [
                            'id' => (int)$v->id,
                            'title' => $v->title,
                            'visibility' => $v->visibility,
                            'price_minor_units' => (int)($v->price_minor_units ?? 0),
                            'thumbnail_url' => $v->thumbnail_path,
                            'creator' => [
                                'id' => (int)$v->creator_profile_id,
                                'user_id' => (int)$v->user_id,
                                'channel_name' => $v->channel_name ?? 'Ramesh K Channel',
                                'channel_slug' => $v->channel_slug ?? 'ramesh-k',
                                'avatar_url' => $v->avatar_path,
                                'is_verified_badge' => (bool)($v->is_verified_badge ?? 1),
                            ],
                        ]
                    ],
                    'errors' => [
                        ['code' => 'VIDEO_REQUIRES_PURCHASE', 'message' => 'This video requires purchase.']
                    ]
                ], 403);
            }
        }

        // Increment real view count when video is accessible
        DB::table('videos')->where('id', $id)->increment('view_count');
        $v->view_count = ((int)$v->view_count) + 1;

        return response()->json([
            'data' => $this->formatVideo($v),
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function homeFeed()
    {
        return $this->index(new Request());
    }

    public function trendingFeed()
    {
        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->whereNull('videos.deleted_at')
            ->orderBy('videos.view_count', 'desc');

        $videos = $query->get();
        $formatted = $videos->map(fn($v) => $this->formatVideo($v))->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => count($formatted)]],
            'errors' => null,
        ]);
    }

    public function latestFeed()
    {
        return $this->index(new Request());
    }

    public function recommendedFeed()
    {
        return $this->index(new Request());
    }

    public function followingFeed(Request $request)
    {
        $user = auth('sanctum')->user() ?? auth('web')->user() ?? \Illuminate\Support\Facades\Auth::user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $followedCreatorIds = DB::table('follows')
            ->where('follower_id', $user->id)
            ->pluck('creator_profile_id')
            ->toArray();

        if (empty($followedCreatorIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 0]],
                'errors' => null,
            ]);
        }

        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->whereIn('videos.creator_profile_id', $followedCreatorIds)
            ->whereNull('videos.deleted_at');

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        $videos = $query->orderBy('videos.created_at', 'desc')->get();
        $formatted = $videos->map(fn($v) => $this->formatVideo($v))->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => count($formatted)]],
            'errors' => null,
        ]);
    }

    public function continueWatching()
    {
        return response()->json(['data' => [], 'meta' => null, 'errors' => null]);
    }

    public function notifications()
    {
        return response()->json(['data' => [], 'meta' => ['unread_count' => 0], 'errors' => null]);
    }

    public function searchVideos(Request $request)
    {
        $q = $request->query('q');

        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->whereNull('videos.deleted_at');

        if ($q) {
            $tagVideoIds = DB::table('video_tag')
                ->join('tags', 'video_tag.tag_id', '=', 'tags.id')
                ->where('tags.name', 'LIKE', '%' . $q . '%')
                ->orWhere('tags.slug', 'LIKE', '%' . $q . '%')
                ->pluck('video_id')
                ->toArray();

            $query->where(function ($sub) use ($q, $tagVideoIds) {
                $sub->where('videos.title', 'LIKE', '%' . $q . '%')
                    ->orWhere('videos.description', 'LIKE', '%' . $q . '%');

                if (!empty($tagVideoIds)) {
                    $sub->orWhereIn('videos.id', $tagVideoIds);
                }
            });
        }

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        $sort = $request->query('sort', 'relevance');
        if ($sort === 'latest') {
            $query->orderBy('videos.created_at', 'desc');
        } elseif ($sort === 'views') {
            $query->orderBy('videos.view_count', 'desc');
        } else {
            $query->orderBy('videos.created_at', 'desc');
        }

        $videos = $query->get();
        $formatted = $videos->map(fn($v) => $this->formatVideo($v))->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => count($formatted)]],
            'errors' => null,
        ]);
    }

    public function searchCreators(Request $request)
    {
        $q = $request->query('q');
        $query = DB::table('creator_profiles');
        if ($q) {
            $query->where('channel_name', 'LIKE', '%' . $q . '%')
                  ->orWhere('channel_slug', 'LIKE', '%' . $q . '%');
        }
        $creatorController = new \App\Http\Controllers\Api\V1\Creator\CreatorController();
        $creators = $query->get();
        $formatted = $creators->map(function ($p) use ($creatorController) {
            $method = new \ReflectionMethod($creatorController, 'formatProfile');
            $method->setAccessible(true);
            return $method->invoke($creatorController, $p, auth('sanctum')->user());
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => count($formatted)]],
            'errors' => null,
        ]);
    }

    public function searchCategories(Request $request)
    {
        $q = $request->query('q');
        $query = DB::table('categories');
        if ($q) {
            $query->where('name', 'LIKE', '%' . $q . '%');
        }
        $categories = $query->get();

        return response()->json([
            'data' => $categories,
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function searchTags(Request $request)
    {
        $q = $request->query('q');
        $query = DB::table('tags');
        if ($q) {
            $query->where('name', 'LIKE', '%' . $q . '%');
        }
        $tags = $query->get();

        return response()->json([
            'data' => $tags,
            'meta' => null,
            'errors' => null,
        ]);
    }
}
