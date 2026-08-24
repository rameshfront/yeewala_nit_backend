<?php

namespace App\Http\Controllers\Api\V1\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Video\VideoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /**
     * List user's watch history.
     */
    public function index(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $videoController = new VideoController();

        $entries = DB::table('watch_history_entries')
            ->join('videos', 'watch_history_entries.video_id', '=', 'videos.id')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->where('watch_history_entries.user_id', $user->id)
            ->whereNull('videos.deleted_at')
            ->select('watch_history_entries.*', 'videos.*', 'creator_profiles.user_id as creator_user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->orderBy('watch_history_entries.last_watched_at', 'desc')
            ->get();

        $formatted = $entries->map(function ($entry) use ($videoController) {
            $formattedVideo = $videoController->formatVideo($entry);
            return [
                'video' => $formattedVideo,
                'progress_seconds' => (int)$entry->progress_seconds,
                'duration_seconds' => $entry->duration_seconds ? (int)$entry->duration_seconds : null,
                'completed' => (bool)$entry->completed,
                'last_watched_at' => $entry->last_watched_at,
            ];
        })->toArray();

        return response()->json([
            'data' => $formatted,
            'meta' => ['pagination' => ['next_cursor' => null, 'per_page' => count($formatted)]],
            'errors' => null,
        ]);
    }

    /**
     * Remove single entry from history.
     */
    public function destroy(Request $request, $videoId)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        DB::table('watch_history_entries')
            ->where('user_id', $user->id)
            ->where('video_id', $videoId)
            ->delete();

        return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
    }

    /**
     * Clear all watch history for user.
     */
    public function clear(Request $request)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        DB::table('watch_history_entries')
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['data' => true, 'meta' => null, 'errors' => null]);
    }

    /**
     * Sync video watch progress.
     */
    public function syncProgress(Request $request, $videoId)
    {
        $user = Auth::user() ?? auth('sanctum')->user();
        if (!$user) {
            return response()->json(['data' => null, 'meta' => null, 'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated']]], 401);
        }

        $progressSeconds = (int)$request->input('progress_seconds', 0);
        $video = DB::table('videos')->where('id', $videoId)->first();
        $durationSeconds = $video ? (int)$video->duration_seconds : 0;
        $completed = ($durationSeconds > 0 && $progressSeconds >= ($durationSeconds - 5)) ? 1 : 0;

        DB::table('watch_history_entries')->updateOrInsert(
            ['user_id' => $user->id, 'video_id' => $videoId],
            [
                'progress_seconds' => $progressSeconds,
                'duration_seconds' => $durationSeconds,
                'completed' => $completed,
                'last_watched_at' => now(),
                'updated_at' => now(),
            ]
        );

        $entry = DB::table('watch_history_entries')
            ->join('videos', 'watch_history_entries.video_id', '=', 'videos.id')
            ->join('creator_profiles', 'videos.creator_profile_id', '=', 'creator_profiles.id')
            ->where('watch_history_entries.user_id', $user->id)
            ->where('watch_history_entries.video_id', $videoId)
            ->select('watch_history_entries.*', 'videos.*', 'creator_profiles.user_id as creator_user_id', 'creator_profiles.channel_name', 'creator_profiles.channel_slug', 'creator_profiles.avatar_path', 'creator_profiles.is_verified_badge')
            ->first();

        $videoController = new VideoController();
        $formattedVideo = $videoController->formatVideo($entry);

        return response()->json([
            'data' => [
                'video' => $formattedVideo,
                'progress_seconds' => (int)$entry->progress_seconds,
                'duration_seconds' => $entry->duration_seconds ? (int)$entry->duration_seconds : null,
                'completed' => (bool)$entry->completed,
                'last_watched_at' => $entry->last_watched_at,
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}
