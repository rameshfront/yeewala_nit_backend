<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminVideoController extends Controller
{
    private function formatAdminVideo($v)
    {
        $avatarUrl = isset($v->avatar_path) && $v->avatar_path
            ? (str_starts_with($v->avatar_path, 'http') ? $v->avatar_path : asset('storage/' . $v->avatar_path))
            : 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=400&auto=format&fit=crop';

        return [
            'id' => (int)$v->id,
            'creator_profile_id' => (int)$v->creator_profile_id,
            'creator' => [
                'id' => (int)$v->creator_profile_id,
                'user_id' => (int)($v->user_id ?? 1),
                'channel_name' => $v->channel_name ?? 'Ramesh K Channel',
                'channel_slug' => $v->channel_slug ?? 'ramesh-k',
                'avatar_url' => $avatarUrl,
                'is_verified_badge' => false,
            ],
            'title' => $v->title,
            'description' => $v->description,
            'type' => $v->type ?? 'long_form',
            'visibility' => $v->visibility ?? 'public',
            'status' => $v->status ?? 'ready',
            'review_status' => $v->review_status ?? 'approved',
            'review_notes' => $v->review_notes ?? null,
            'reviewed_by' => null,
            'reviewed_at' => $v->reviewed_at ?? null,
            'is_hidden' => (bool)($v->is_hidden ?? 0),
            'is_featured' => (bool)($v->is_featured ?? 0),
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
            'my_reaction' => null,
            'scheduled_at' => null,
            'published_at' => $v->published_at,
            'thumbnail_url' => $v->thumbnail_path ?? null,
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
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path');

        if ($request->boolean('trashed')) {
            $query->whereNotNull('videos.deleted_at');
        } else {
            $query->whereNull('videos.deleted_at');
        }

        if ($request->filled('review_status')) {
            $query->where('videos.review_status', $request->query('review_status'));
        }

        if ($request->filled('visibility')) {
            $query->where('videos.visibility', $request->query('visibility'));
        }

        if ($request->filled('type')) {
            $query->where('videos.type', $request->query('type'));
        }

        if ($request->filled('status')) {
            $query->where('videos.status', $request->query('status'));
        }

        if ($request->boolean('hidden')) {
            $query->where('videos.is_hidden', 1);
        }

        if ($request->boolean('flagged')) {
            $query->where('videos.is_hidden', 1);
        }

        $videos = $query->orderBy('videos.id', 'desc')->get();

        $formatted = $videos->map(fn($v) => $this->formatAdminVideo($v))->toArray();

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
        $v = DB::table('videos')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->select('videos.*', 'creator_profiles.user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path')
            ->where('videos.id', $id)
            ->first();

        if (!$v) {
            return response()->json(['message' => 'Video not found.'], 404);
        }

        return response()->json([
            'data' => $this->formatAdminVideo($v),
            'meta' => null,
            'errors' => null,
        ]);
    }

    public function approve($id)
    {
        DB::table('videos')->where('id', $id)->update([
            'review_status' => 'approved',
            'status' => 'published',
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function reject(Request $request, $id)
    {
        $reason = $request->input('reason', 'Content violates platform guidelines.');
        DB::table('videos')->where('id', $id)->update([
            'review_status' => 'rejected',
            'review_notes' => $reason,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function requestChanges(Request $request, $id)
    {
        $notes = $request->input('notes', 'Please update the video description or metadata.');
        DB::table('videos')->where('id', $id)->update([
            'review_status' => 'changes_requested',
            'review_notes' => $notes,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function publish(Request $request, $id)
    {
        $visibility = $request->input('visibility', 'public');
        $priceMinor = $request->input('price_minor_units');

        DB::table('videos')->where('id', $id)->update([
            'visibility' => $visibility,
            'price_minor_units' => $priceMinor,
            'status' => 'published',
            'published_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function feature($id)
    {
        DB::table('videos')->where('id', $id)->update(['is_featured' => 1, 'updated_at' => now()]);
        return $this->show($id);
    }

    public function unfeature($id)
    {
        DB::table('videos')->where('id', $id)->update(['is_featured' => 0, 'updated_at' => now()]);
        return $this->show($id);
    }

    public function hide($id)
    {
        DB::table('videos')->where('id', $id)->update(['is_hidden' => 1, 'updated_at' => now()]);
        return $this->show($id);
    }

    public function unhide($id)
    {
        DB::table('videos')->where('id', $id)->update(['is_hidden' => 0, 'updated_at' => now()]);
        return $this->show($id);
    }

    public function destroy(Request $request, $id)
    {
        DB::table('videos')->where('id', $id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function restore($id)
    {
        DB::table('videos')->where('id', $id)->update([
            'deleted_at' => null,
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function forceDelete($id)
    {
        DB::table('videos')->where('id', $id)->delete();
        return response()->json(['message' => 'Video permanently deleted.'], 200);
    }
}
