<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $category = null;
        if (!empty($v->category_id)) {
            $cat = DB::table('categories')->where('id', $v->category_id)->first();
            if ($cat) {
                $category = [
                    'id' => (int)$cat->id,
                    'parent_id' => $cat->parent_id ? (int)$cat->parent_id : null,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'is_active' => (bool)($cat->is_active ?? true),
                ];
            }
        }

        $tags = [];
        if (isset($v->id)) {
            $tags = DB::table('video_tag')
                ->join('tags', 'video_tag.tag_id', '=', 'tags.id')
                ->where('video_tag.video_id', $v->id)
                ->select('tags.id', 'tags.name', 'tags.slug')
                ->get()
                ->map(fn($t) => [
                    'id' => (int)$t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ])
                ->toArray();
        }

        $renditions = [];
        if (isset($v->id)) {
            $renditions = DB::table('video_renditions')
                ->where('video_id', $v->id)
                ->get()
                ->map(fn($r) => [
                    'resolution' => $r->resolution,
                    'width' => (int)$r->width,
                    'height' => (int)$r->height,
                    'bitrate_kbps' => (int)$r->bitrate_kbps,
                ])
                ->toArray();
        }

        $captions = [];
        if (isset($v->id)) {
            $captions = DB::table('video_captions')
                ->where('video_id', $v->id)
                ->get()
                ->map(fn($c) => [
                    'id' => (int)$c->id,
                    'video_id' => (int)$c->video_id,
                    'language_code' => $c->language_code,
                    'label' => $c->label,
                    'source' => $c->source,
                    'vtt_url' => $c->vtt_path,
                    'created_at' => $c->created_at,
                    'updated_at' => $c->updated_at,
                ])
                ->toArray();
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
            'category' => $category,
            'tags' => $tags,
            'renditions' => $renditions,
            'captions' => $captions,
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

    /**
     * PATCH /api/v1/videos/{id}
     * Update video metadata (title, description, category, tags).
     */
    public function update(Request $request, $id)
    {
        $video = DB::table('videos')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$video) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']],
            ], 404);
        }

        $user = auth('sanctum')->user() ?? auth('web')->user();
        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']],
            ], 401);
        }

        $creatorProfile = DB::table('creator_profiles')->where('id', $video->creator_profile_id)->first();
        $isOwner = $creatorProfile && (int)$creatorProfile->user_id === (int)$user->id;
        $isAdmin = (bool)($user->is_admin ?? false);

        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'You do not have permission to edit this video']],
            ], 403);
        }

        $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
            'tags'        => 'nullable|array',
        ]);

        $updates = [];
        if ($request->has('title')) {
            $updates['title'] = $request->input('title');
        }
        if ($request->has('description')) {
            $updates['description'] = $request->input('description');
        }
        if ($request->has('category_id')) {
            $catId = $request->input('category_id');
            if ($catId) {
                $catExists = DB::table('categories')->where('id', $catId)->exists();
                $updates['category_id'] = $catExists ? $catId : null;
            } else {
                $updates['category_id'] = null;
            }
        }
        $updates['updated_at'] = now();

        DB::table('videos')->where('id', $id)->update($updates);

        // Sync tags if provided
        if ($request->has('tags')) {
            $tagInputs = $request->input('tags', []);
            DB::table('video_tag')->where('video_id', $id)->delete();

            foreach ($tagInputs as $tagName) {
                $tagName = trim((string)$tagName);
                if ($tagName === '') continue;

                $slug = Str::slug($tagName);
                if (!$slug) {
                    $slug = 'tag-' . md5($tagName);
                }

                $tag = DB::table('tags')->where('name', $tagName)->orWhere('slug', $slug)->first();
                if (!$tag) {
                    $tagId = DB::table('tags')->insertGetId([
                        'name'       => $tagName,
                        'slug'       => $slug,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $tagId = $tag->id;
                }

                DB::table('video_tag')->insertOrIgnore([
                    'video_id' => $id,
                    'tag_id'   => $tagId,
                ]);
            }
        }

        $freshVideo = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.id', $id)
            ->first();

        return response()->json([
            'data'   => $this->formatVideo($freshVideo),
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * PATCH /api/v1/videos/{id}/resubmit
     */
    public function resubmit($id)
    {
        $video = DB::table('videos')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$video) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']],
            ], 404);
        }

        $user = auth('sanctum')->user() ?? auth('web')->user();
        $creatorProfile = DB::table('creator_profiles')->where('id', $video->creator_profile_id)->first();
        $isOwner = $creatorProfile && (int)$creatorProfile->user_id === (int)$user?->id;
        $isAdmin = (bool)($user?->is_admin ?? false);

        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'Forbidden']],
            ], 403);
        }

        DB::table('videos')->where('id', $id)->update([
            'review_status' => 'pending_review',
            'updated_at'    => now(),
        ]);

        $freshVideo = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.id', $id)
            ->first();

        return response()->json([
            'data'   => $this->formatVideo($freshVideo),
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * DELETE /api/v1/videos/{id}
     */
    public function destroy($id)
    {
        $video = DB::table('videos')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$video) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']],
            ], 404);
        }

        $user = auth('sanctum')->user() ?? auth('web')->user();
        $creatorProfile = DB::table('creator_profiles')->where('id', $video->creator_profile_id)->first();
        $isOwner = $creatorProfile && (int)$creatorProfile->user_id === (int)$user?->id;
        $isAdmin = (bool)($user?->is_admin ?? false);

        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'Forbidden']],
            ], 403);
        }

        DB::table('videos')->where('id', $id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data'   => null,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * GET /api/v1/videos/{id}/renditions
     */
    public function renditions($id)
    {
        $renditions = DB::table('video_renditions')
            ->where('video_id', $id)
            ->get()
            ->map(fn($r) => [
                'resolution'   => $r->resolution,
                'width'        => (int)$r->width,
                'height'       => (int)$r->height,
                'bitrate_kbps' => (int)$r->bitrate_kbps,
            ])
            ->toArray();

        return response()->json([
            'data'   => $renditions,
            'meta'   => null,
            'errors' => null,
        ]);
    }

    /**
     * POST /api/v1/videos/{id}/captions
     */
    public function uploadCaption(Request $request, $id)
    {
        $request->validate([
            'language_code' => 'required|string|max:10',
            'label'         => 'required|string|max:255',
            'vtt'           => 'required|file',
        ]);

        $video = DB::table('videos')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$video) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Video not found']],
            ], 404);
        }

        $path = $request->file('vtt')->store("captions/{$id}", 'public');
        $captionId = DB::table('video_captions')->insertGetId([
            'video_id'      => $id,
            'language_code' => $request->language_code,
            'label'         => $request->label,
            'vtt_path'      => Storage::disk('public')->url($path),
            'source'        => 'creator_upload',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $caption = DB::table('video_captions')->where('id', $captionId)->first();

        return response()->json([
            'data' => [
                'id'            => (int)$caption->id,
                'video_id'      => (int)$caption->video_id,
                'language_code' => $caption->language_code,
                'label'         => $caption->label,
                'source'        => $caption->source,
                'vtt_url'       => $caption->vtt_path,
                'created_at'    => $caption->created_at,
                'updated_at'    => $caption->updated_at,
            ],
            'meta'   => null,
            'errors' => null,
        ], 201);
    }

    /**
     * DELETE /api/v1/videos/{id}/captions/{captionId}
     */
    public function deleteCaption($videoId, $captionId)
    {
        DB::table('video_captions')
            ->where('video_id', $videoId)
            ->where('id', $captionId)
            ->delete();

        return response()->json([
            'data'   => null,
            'meta'   => null,
            'errors' => null,
        ]);
    }
}
