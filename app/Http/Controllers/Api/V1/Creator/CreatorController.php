<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Video\VideoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatorController extends Controller
{
    /**
     * Get or initialize current user's creator profile for dashboard.
     */
    public function getDashboard(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profile = DB::table('creator_profiles')->where('user_id', $user->id)->first();

        if (!$profile) {
            $slug = Str::slug($user->name) ?: 'user-' . $user->id;
            // Check slug uniqueness
            $count = DB::table('creator_profiles')->where('channel_slug', $slug)->count();
            if ($count > 0) {
                $slug .= '-' . $user->id;
            }

            $profileId = DB::table('creator_profiles')->insertGetId([
                'user_id' => $user->id,
                'channel_name' => $user->name,
                'channel_slug' => $slug,
                'bio' => null,
                'avatar_path' => $user->avatar_path ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400&auto=format&fit=crop',
                'banner_path' => null,
                'is_verified_badge' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $profile = DB::table('creator_profiles')->where('id', $profileId)->first();
        }

        return response()->json([
            'data' => $this->formatProfile($profile, $user),
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Get current user's creator profile.
     */
    public function getProfile(Request $request)
    {
        return $this->getDashboard($request);
    }

    /**
     * Get public creator profile by ID or Channel Slug.
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        $profile = is_numeric($id)
            ? DB::table('creator_profiles')->where('id', (int)$id)->first()
            : DB::table('creator_profiles')->where('channel_slug', $id)->first();

        // If not found in creator_profiles, check if user exists (e.g. user-8 or user name)
        if (!$profile) {
            $userLookup = null;
            if (is_numeric($id)) {
                $userLookup = DB::table('users')->where('id', (int)$id)->first();
            } elseif (str_starts_with($id, 'user-')) {
                $uid = (int) substr($id, 5);
                $userLookup = DB::table('users')->where('id', $uid)->first();
            } else {
                $userLookup = DB::table('users')
                    ->where('name', 'like', str_replace('-', ' ', $id))
                    ->orWhere('email', 'like', $id . '%')
                    ->first();
            }

            if ($userLookup) {
                // Check if profile exists for this user_id
                $profile = DB::table('creator_profiles')->where('user_id', $userLookup->id)->first();

                if (!$profile) {
                    $slug = \Illuminate\Support\Str::slug($userLookup->name) ?: 'user-' . $userLookup->id;
                    $exists = DB::table('creator_profiles')->where('channel_slug', $slug)->exists();
                    if ($exists) {
                        $slug = $slug . '-' . $userLookup->id;
                    }

                    $profileId = DB::table('creator_profiles')->insertGetId([
                        'user_id' => $userLookup->id,
                        'channel_name' => $userLookup->name,
                        'channel_slug' => $slug,
                        'avatar_path' => $userLookup->avatar_path,
                        'bio' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $profile = DB::table('creator_profiles')->where('id', $profileId)->first();
                }
            }
        }

        if (!$profile) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Creator not found']]], 404);
        }

        return response()->json([
            'data' => $this->formatProfile($profile, $user),
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Get published videos for a specific creator by ID or Slug.
     */
    public function getCreatorVideos(Request $request, $creatorProfileId)
    {
        $videoController = new VideoController();

        $profile = is_numeric($creatorProfileId)
            ? DB::table('creator_profiles')->where('id', (int)$creatorProfileId)->first()
            : DB::table('creator_profiles')->where('channel_slug', $creatorProfileId)->first();

        if (!$profile && str_starts_with((string)$creatorProfileId, 'user-')) {
            $uid = (int) substr((string)$creatorProfileId, 5);
            $profile = DB::table('creator_profiles')->where('user_id', $uid)->first();
        }

        if (!$profile) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 0]],
                'errors' => null,
            ]);
        }

        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.creator_profile_id', $profile->id)
            ->whereNull('videos.deleted_at');

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        $videos = $query->orderBy('videos.created_at', 'desc')->get();
        $formatted = $videos->map(fn($v) => $videoController->formatVideo($v))->toArray();

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

    /**
     * Follow a creator profile.
     */
    public function follow(Request $request, $creatorProfileId)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profile = is_numeric($creatorProfileId)
            ? DB::table('creator_profiles')->where('id', (int)$creatorProfileId)->first()
            : DB::table('creator_profiles')->where('channel_slug', $creatorProfileId)->first();

        if (!$profile) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Creator profile not found']]], 404);
        }

        $exists = DB::table('follows')
            ->where('creator_profile_id', $profile->id)
            ->where('follower_id', $user->id)
            ->exists();

        if (!$exists) {
            DB::table('follows')->insert([
                'follower_id' => $user->id,
                'creator_profile_id' => $profile->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $this->formatProfile($profile, $user),
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Unfollow a creator profile.
     */
    public function unfollow(Request $request, $creatorProfileId)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profile = is_numeric($creatorProfileId)
            ? DB::table('creator_profiles')->where('id', (int)$creatorProfileId)->first()
            : DB::table('creator_profiles')->where('channel_slug', $creatorProfileId)->first();

        if (!$profile) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Creator profile not found']]], 404);
        }

        DB::table('follows')
            ->where('creator_profile_id', $profile->id)
            ->where('follower_id', $user->id)
            ->delete();

        return response()->json([
            'data' => $this->formatProfile($profile, $user),
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * List creator profiles followed by current user.
     */
    public function listFollowing(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profiles = DB::table('follows')
            ->join('creator_profiles', 'follows.creator_profile_id', '=', 'creator_profiles.id')
            ->where('follows.follower_id', $user->id)
            ->select('creator_profiles.*')
            ->get();

        $formatted = $profiles->map(fn($p) => $this->formatProfile($p, $user))->toArray();

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

    /**
     * List users following current user's creator channel.
     */
    public function listFollowers(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $myProfile = DB::table('creator_profiles')->where('user_id', $user->id)->first();
        if (!$myProfile) {
            return response()->json([
                'data' => [],
                'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => 0]],
                'errors' => null,
            ]);
        }

        $followers = DB::table('follows')
            ->join('users', 'follows.follower_id', '=', 'users.id')
            ->leftJoin('creator_profiles', 'users.id', '=', 'creator_profiles.user_id')
            ->where('follows.creator_profile_id', $myProfile->id)
            ->select(
                'users.id as user_id',
                'users.name',
                'users.avatar_path as user_avatar',
                'creator_profiles.id as creator_profile_id',
                'creator_profiles.channel_name',
                'creator_profiles.channel_slug',
                'creator_profiles.avatar_path as creator_avatar',
                'creator_profiles.is_verified_badge',
                'follows.created_at as followed_at'
            )
            ->orderBy('follows.created_at', 'desc')
            ->get();

        $formatted = $followers->map(function ($f) use ($user) {
            $avatarUrl = $f->creator_avatar ?? $f->user_avatar;
            if ($avatarUrl && !str_starts_with($avatarUrl, 'http://') && !str_starts_with($avatarUrl, 'https://')) {
                $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
                if (!str_starts_with($avatarUrl, '/storage/') && !str_starts_with($avatarUrl, 'storage/')) {
                    $avatarUrl = '/storage/' . ltrim($avatarUrl, '/');
                }
                $avatarUrl = $baseUrl . '/' . ltrim($avatarUrl, '/');
            }

            return [
                'id' => (int)($f->creator_profile_id ?? $f->user_id),
                'user_id' => (int)$f->user_id,
                'channel_name' => $f->channel_name ?? $f->name,
                'channel_slug' => $f->channel_slug ?? 'user-' . $f->user_id,
                'avatar_url' => $avatarUrl ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400&auto=format&fit=crop',
                'is_verified_badge' => (bool)($f->is_verified_badge ?? 0),
                'followed_at' => $f->followed_at,
            ];
        })->toArray();

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

    /**
     * Format creator profile array for frontend.
     */
    private function formatProfile($p, $currentUser = null): array
    {
        $avatarUrl = $p->avatar_path;
        if ($avatarUrl && !str_starts_with($avatarUrl, 'http://') && !str_starts_with($avatarUrl, 'https://')) {
            $baseUrl = rtrim(config('app.url', 'http://localhost:8000'), '/');
            if (!str_starts_with($avatarUrl, '/storage/') && !str_starts_with($avatarUrl, 'storage/')) {
                $avatarUrl = '/storage/' . ltrim($avatarUrl, '/');
            }
            $avatarUrl = $baseUrl . '/' . ltrim($avatarUrl, '/');
        }

        $followerCount = DB::table('follows')->where('creator_profile_id', $p->id)->count();
        $isFollowing = false;
        if ($currentUser) {
            $isFollowing = DB::table('follows')
                ->where('creator_profile_id', $p->id)
                ->where('follower_id', $currentUser->id)
                ->exists();
        }

        $videoCount = DB::table('videos')->where('creator_profile_id', $p->id)->whereNull('deleted_at')->count();

        return [
            'id' => (int)$p->id,
            'user_id' => (int)$p->user_id,
            'channel_name' => $p->channel_name,
            'channel_slug' => $p->channel_slug,
            'bio' => $p->bio,
            'avatar_url' => $avatarUrl ?? 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400&auto=format&fit=crop',
            'banner_url' => $p->banner_path,
            'is_verified_badge' => (bool)($p->is_verified_badge ?? 1),
            'follower_count' => (int)$followerCount,
            'subscriber_count' => (int)$followerCount,
            'is_following' => (bool)$isFollowing,
            'video_count' => (int)$videoCount,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
        ];
    }
}
