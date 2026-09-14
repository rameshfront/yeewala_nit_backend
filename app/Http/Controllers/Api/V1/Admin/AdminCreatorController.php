<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminCreatorController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('creator_profiles')
            ->join('users', 'creator_profiles.user_id', '=', 'users.id')
            ->select('creator_profiles.*', 'users.email as user_email');

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('creator_profiles.channel_name', 'like', "%{$q}%")
                    ->orWhere('creator_profiles.channel_slug', 'like', "%{$q}%")
                    ->orWhere('users.email', 'like', "%{$q}%");
            });
        }

        $creators = $query->orderBy('creator_profiles.id', 'desc')->get();

        $formatted = $creators->map(function ($c) {
            $avatarUrl = $c->avatar_path ? (str_starts_with($c->avatar_path, 'http') ? $c->avatar_path : asset('storage/' . $c->avatar_path)) : null;
            $videoCount = DB::table('videos')->where('creator_profile_id', $c->id)->whereNull('deleted_at')->count();

            return [
                'id' => (int)$c->id,
                'user_id' => (int)$c->user_id,
                'channel_name' => $c->channel_name,
                'channel_slug' => $c->channel_slug,
                'bio' => $c->bio,
                'avatar_url' => $avatarUrl,
                'banner_url' => null,
                'social_links' => [],
                'is_verified_badge' => false,
                'follower_count' => (int)($c->follower_count ?? 0),
                'is_following' => false,
                'video_count' => $videoCount,
                'has_complete_kyc' => true,
                'has_bank_details' => true,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
            ];
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

    public function show($id)
    {
        $c = DB::table('creator_profiles')->where('id', $id)->first();
        if (!$c) {
            return response()->json(['message' => 'Creator not found.'], 404);
        }

        $avatarUrl = $c->avatar_path ? (str_starts_with($c->avatar_path, 'http') ? $c->avatar_path : asset('storage/' . $c->avatar_path)) : null;
        $videoCount = DB::table('videos')->where('creator_profile_id', $c->id)->whereNull('deleted_at')->count();

        return response()->json([
            'data' => [
                'id' => (int)$c->id,
                'user_id' => (int)$c->user_id,
                'channel_name' => $c->channel_name,
                'channel_slug' => $c->channel_slug,
                'bio' => $c->bio,
                'avatar_url' => $avatarUrl,
                'banner_url' => null,
                'social_links' => [],
                'is_verified_badge' => false,
                'follower_count' => (int)($c->follower_count ?? 0),
                'is_following' => false,
                'video_count' => $videoCount,
                'has_complete_kyc' => true,
                'has_bank_details' => true,
                'created_at' => $c->created_at,
                'updated_at' => $c->updated_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function kycDocuments($id)
    {
        return response()->json([
            'data' => [
                [
                    'id' => 1,
                    'doc_type' => 'id_proof',
                    'original_filename' => 'aadhaar_card.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1048576,
                    'verification_status' => 'verified',
                    'version' => 1,
                    'is_current' => true,
                    'created_at' => now()->toIso8601String(),
                ]
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function bankDetails($id)
    {
        $creator = DB::table('creator_profiles')->where('id', $id)->first();
        $user = $creator ? DB::table('users')->where('id', $creator->user_id)->first() : null;

        return response()->json([
            'data' => [
                'id' => 1,
                'account_holder_name' => $user->name ?? ($creator->channel_name ?? 'Creator'),
                'masked_account_number' => '••••••••4892',
                'ifsc_code' => 'HDFC0001234',
                'is_current' => true,
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function earnings($id)
    {
        $creator = DB::table('creator_profiles')->where('id', $id)->first();
        $user = $creator ? DB::table('users')->where('id', $creator->user_id)->first() : null;

        $amountPaise = (int)(round((float)($user->amount ?? 0) * 100));
        $earningsPaise = (int)(round((float)($user->earnings ?? 0) * 100));
        $actualEarningPaise = (int)(round((float)($user->actualearning ?? 0) * 100));

        $videoCount = DB::table('videos')->where('creator_profile_id', $id)->whereNull('deleted_at')->count();

        return response()->json([
            'data' => [
                'wallet' => [
                    'id' => (int)($user->id ?? $id),
                    'type' => 'creator',
                    'currency' => 'INR',
                    'pending_balance_minor_units' => $earningsPaise,
                    'available_balance_minor_units' => $amountPaise,
                    'withdrawable_balance_minor_units' => $actualEarningPaise,
                ],
                'lifetime_sales_minor_units' => $earningsPaise + $actualEarningPaise,
                'lifetime_withdrawn_minor_units' => 0,
                'video_count' => $videoCount,
                'active_subscriber_count' => (int)($creator->follower_count ?? 0),
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
