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
     * Get public creator profile by ID.
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        $profile = DB::table('creator_profiles')->where('id', $id)->first();
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
     * Get published videos for a specific creator.
     */
    public function getCreatorVideos(Request $request, $creatorProfileId)
    {
        $videoController = new VideoController();

        $query = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->where('videos.creator_profile_id', $creatorProfileId)
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

        $profile = DB::table('creator_profiles')->where('id', $creatorProfileId)->first();
        if (!$profile) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'NOT_FOUND', 'message' => 'Creator profile not found']]], 404);
        }

        $exists = DB::table('follows')
            ->where('creator_profile_id', $creatorProfileId)
            ->where('follower_id', $user->id)
            ->exists();

        if (!$exists) {
            DB::table('follows')->insert([
                'follower_id' => $user->id,
                'creator_profile_id' => $creatorProfileId,
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

        DB::table('follows')
            ->where('creator_profile_id', $creatorProfileId)
            ->where('follower_id', $user->id)
            ->delete();

        return response()->json([
            'data' => true,
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
