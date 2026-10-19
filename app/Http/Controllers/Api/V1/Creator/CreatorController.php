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

    /**
     * Get or create creator profile helper.
     */
    private function getOrCreateCreatorProfile($userId, $userName = 'Creator')
    {
        $profile = DB::table('creator_profiles')->where('user_id', $userId)->first();
        if (!$profile) {
            $slug = Str::slug($userName) ?: 'user-' . $userId;
            $count = DB::table('creator_profiles')->where('channel_slug', $slug)->count();
            if ($count > 0) {
                $slug .= '-' . $userId;
            }
            $profileId = DB::table('creator_profiles')->insertGetId([
                'user_id' => $userId,
                'channel_name' => $userName,
                'channel_slug' => $slug,
                'is_verified_badge' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $profile = DB::table('creator_profiles')->where('id', $profileId)->first();
        }
        return $profile;
    }

    /**
     * List KYC documents for current creator.
     */
    public function listKycDocuments(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profile = $this->getOrCreateCreatorProfile($user->id, $user->name);

        $docs = DB::table('kyc_documents')
            ->where('creator_profile_id', $profile->id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn($d) => [
                'id' => (int)$d->id,
                'doc_type' => $d->doc_type,
                'original_filename' => $d->original_filename,
                'mime_type' => $d->mime_type,
                'size_bytes' => (int)$d->size_bytes,
                'verification_status' => $d->verification_status ?? 'pending',
                'version' => (int)($d->version ?? 1),
                'is_current' => (bool)$d->is_current,
                'created_at' => $d->created_at,
            ]);

        return response()->json([
            'data' => $docs,
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Upload KYC document for current creator.
     */
    public function uploadKycDocument(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $validated = $request->validate([
            'doc_type' => 'required|in:id_proof,address_proof',
            'file' => 'required|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ]);

        $profile = $this->getOrCreateCreatorProfile($user->id, $user->name);
        $file = $request->file('file');
        $path = $file->store('kyc_documents', 'local');

        // Mark older documents of same type as not current
        DB::table('kyc_documents')
            ->where('creator_profile_id', $profile->id)
            ->where('doc_type', $validated['doc_type'])
            ->update(['is_current' => false]);

        $currentVersion = DB::table('kyc_documents')
            ->where('creator_profile_id', $profile->id)
            ->where('doc_type', $validated['doc_type'])
            ->count();

        $id = DB::table('kyc_documents')->insertGetId([
            'creator_profile_id' => $profile->id,
            'doc_type' => $validated['doc_type'],
            'disk_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'verification_status' => 'pending',
            'version' => $currentVersion + 1,
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $doc = DB::table('kyc_documents')->where('id', $id)->first();

        return response()->json([
            'data' => [
                'id' => (int)$doc->id,
                'doc_type' => $doc->doc_type,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'size_bytes' => (int)$doc->size_bytes,
                'verification_status' => $doc->verification_status,
                'version' => (int)$doc->version,
                'is_current' => (bool)$doc->is_current,
                'created_at' => $doc->created_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Get bank details for current creator.
     */
    public function getBankDetails(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $profile = $this->getOrCreateCreatorProfile($user->id, $user->name);

        $bank = DB::table('bank_details')
            ->where('creator_profile_id', $profile->id)
            ->where('is_current', true)
            ->first();

        if (!$bank) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Bank details not found']],
            ], 404);
        }

        $acc = (string)$bank->account_number;
        $masked = strlen($acc) > 4 ? str_repeat('•', max(0, strlen($acc) - 4)) . substr($acc, -4) : $acc;

        return response()->json([
            'data' => [
                'id' => (int)$bank->id,
                'bank_name' => $bank->bank_name ?? '',
                'account_holder_name' => $bank->account_holder_name,
                'masked_account_number' => $masked,
                'ifsc_code' => $bank->ifsc_code,
                'is_current' => (bool)$bank->is_current,
                'created_at' => $bank->created_at,
                'updated_at' => $bank->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    /**
     * Save bank details for current creator.
     */
    public function saveBankDetails(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $validated = $request->validate([
            'bank_name' => 'required|string|min:2|max:255',
            'account_holder_name' => 'required|string|min:2|max:255',
            'account_number' => 'required|string|regex:/^\d{9,18}$/',
            'ifsc_code' => 'required|string|regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/',
            'pan_number' => 'nullable|string|max:20',
        ]);

        $profile = $this->getOrCreateCreatorProfile($user->id, $user->name);

        // Mark previous bank details as not current
        DB::table('bank_details')
            ->where('creator_profile_id', $profile->id)
            ->update(['is_current' => false]);

        $id = DB::table('bank_details')->insertGetId([
            'creator_profile_id' => $profile->id,
            'bank_name' => $validated['bank_name'],
            'account_holder_name' => $validated['account_holder_name'],
            'account_number' => $validated['account_number'],
            'ifsc_code' => strtoupper($validated['ifsc_code']),
            'pan_number' => !empty($validated['pan_number']) ? strtoupper($validated['pan_number']) : null,
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bank = DB::table('bank_details')->where('id', $id)->first();
        $acc = (string)$bank->account_number;
        $masked = strlen($acc) > 4 ? str_repeat('•', max(0, strlen($acc) - 4)) . substr($acc, -4) : $acc;

        return response()->json([
            'data' => [
                'id' => (int)$bank->id,
                'bank_name' => $bank->bank_name ?? '',
                'account_holder_name' => $bank->account_holder_name,
                'masked_account_number' => $masked,
                'ifsc_code' => $bank->ifsc_code,
                'is_current' => (bool)$bank->is_current,
                'created_at' => $bank->created_at,
                'updated_at' => $bank->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
