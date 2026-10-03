<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use App\Models\UploadSession;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Default chunk size: 10 MB.
     * The frontend slices the file into chunks of this size.
     */
    private const CHUNK_SIZE = 10 * 1024 * 1024;

    /**
     * How many hours an upload session stays alive before expiry.
     */
    private const SESSION_TTL_HOURS = 6;

    /**
     * Allowed MIME types for video uploads.
     */
    private const ALLOWED_MIMES = [
        'video/mp4',
        'video/quicktime',   // .mov
        'video/x-matroska',  // .mkv
        'video/x-msvideo',   // .avi
        'video/webm',
    ];

    /**
     * Maximum upload size: 5 GB.
     */
    private const MAX_SIZE = 5 * 1024 * 1024 * 1024;

    // ─── Format a session for the JSON response ────────────────────

    private function formatSession(UploadSession $session): array
    {
        return [
            'upload_id'        => $session->upload_id,
            'video_id'         => $session->video_id,
            'original_filename'=> $session->original_filename,
            'mime_type'        => $session->mime_type,
            'total_size_bytes' => (int) $session->total_size_bytes,
            'chunk_size_bytes' => (int) $session->chunk_size_bytes,
            'total_chunks'     => (int) $session->total_chunks,
            'received_chunks'  => $session->received_chunks ?? [],
            'status'           => $session->status,
            'type'             => $session->type ?? 'long_form',
            'expires_at'       => $session->expires_at?->toIso8601String(),
            'created_at'       => $session->created_at?->toIso8601String(),
        ];
    }

    // ─── 1. Initiate Upload ────────────────────────────────────────

    /**
     * POST /api/v1/videos/uploads
     *
     * Creates a new chunked-upload session and a stub Video row.
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'original_filename'  => 'required|string|max:255',
            'mime_type'          => 'required|string',
            'total_size_bytes'   => 'required|integer|min:1',
            'type'               => 'nullable|string|in:long_form,short',
        ]);

        // Validate MIME type
        if (!in_array($request->mime_type, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'INVALID_MIME', 'message' => 'Unsupported video format.']],
            ], 422);
        }

        // Validate size
        if ($request->total_size_bytes > self::MAX_SIZE) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'FILE_TOO_LARGE', 'message' => 'File exceeds the 5 GB limit.']],
            ], 422);
        }

        $user = $request->user();

        // Get or create a creator profile for this user
        $creatorProfile = DB::table('creator_profiles')
            ->where('user_id', $user->id)
            ->first();

        if (!$creatorProfile) {
            $profileId = DB::table('creator_profiles')->insertGetId([
                'user_id'      => $user->id,
                'channel_name' => $user->name,
                'channel_slug' => Str::slug($user->name) . '-' . Str::random(4),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } else {
            $profileId = $creatorProfile->id;
        }

        $type       = $request->input('type', 'long_form');
        $uploadId   = (string) Str::uuid();
        $totalChunks = (int) ceil($request->total_size_bytes / self::CHUNK_SIZE);

        // Create a stub Video row so the upload has a destination
        $videoId = DB::table('videos')->insertGetId([
            'creator_profile_id' => $profileId,
            'title'              => pathinfo($request->original_filename, PATHINFO_FILENAME),
            'type'               => $type,
            'status'             => 'uploading',
            'original_filename'  => $request->original_filename,
            'mime_type'          => $request->mime_type,
            'size_bytes'         => $request->total_size_bytes,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Create the upload session
        $session = UploadSession::create([
            'upload_id'          => $uploadId,
            'creator_profile_id' => $profileId,
            'video_id'           => $videoId,
            'type'               => $type,
            'original_filename'  => $request->original_filename,
            'mime_type'          => $request->mime_type,
            'total_size_bytes'   => $request->total_size_bytes,
            'chunk_size_bytes'   => self::CHUNK_SIZE,
            'total_chunks'       => $totalChunks,
            'received_chunks'    => [],
            'status'             => 'uploading',
            'expires_at'         => now()->addHours(self::SESSION_TTL_HOURS),
        ]);

        return response()->json([
            'data' => $this->formatSession($session),
            'meta' => null,
            'errors' => null,
        ], 201);
    }

    // ─── 2. Upload a Chunk ─────────────────────────────────────────

    /**
     * POST /api/v1/videos/uploads/{uploadId}/chunks
     *
     * Receives a single chunk blob and stores it to S3.
     */
    public function uploadChunk(Request $request, string $uploadId)
    {
        $request->validate([
            'chunk_index' => 'required|integer|min:0',
            'chunk'       => 'required|file',
        ]);

        $session = UploadSession::where('upload_id', $uploadId)->first();

        if (!$session) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Upload session not found.']],
            ], 404);
        }

        if ($session->status !== 'uploading') {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'SESSION_CLOSED', 'message' => 'This upload session is no longer accepting chunks.']],
            ], 409);
        }

        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired']);
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'SESSION_EXPIRED', 'message' => 'Upload session has expired.']],
            ], 410);
        }

        $chunkIndex = (int) $request->chunk_index;

        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'INVALID_CHUNK', 'message' => 'Chunk index is out of range.']],
            ], 422);
        }

        // Store chunk to S3 under a temporary path
        $chunkPath = "uploads/{$uploadId}/chunk-{$chunkIndex}";
        $file = $request->file('chunk');

        Storage::disk('s3')->put($chunkPath, file_get_contents($file->getRealPath()));

        // Update received_chunks
        $received = $session->received_chunks ?? [];
        if (!in_array($chunkIndex, $received, true)) {
            $received[] = $chunkIndex;
            sort($received);
        }
        $session->update(['received_chunks' => $received]);

        return response()->json([
            'data' => $this->formatSession($session->fresh()),
            'meta' => null,
            'errors' => null,
        ]);
    }

    // ─── 3. Get Upload Session ─────────────────────────────────────

    /**
     * GET /api/v1/videos/uploads/{uploadId}
     *
     * Returns the current state of an upload session (used for polling).
     */
    public function show(string $uploadId)
    {
        $session = UploadSession::where('upload_id', $uploadId)->first();

        if (!$session) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Upload session not found.']],
            ], 404);
        }

        return response()->json([
            'data' => $this->formatSession($session),
            'meta' => null,
            'errors' => null,
        ]);
    }

    // ─── 4. Finalize Upload ────────────────────────────────────────

    /**
     * POST /api/v1/videos/uploads/{uploadId}/finalize
     *
     * Verifies all chunks are received, assembles them into a single
     * source file on S3, and marks the video as ready for processing.
     */
    public function finalize(string $uploadId)
    {
        $session = UploadSession::where('upload_id', $uploadId)->first();

        if (!$session) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'NOT_FOUND', 'message' => 'Upload session not found.']],
            ], 404);
        }

        if ($session->status !== 'uploading') {
            return response()->json([
                'data' => $this->formatSession($session),
                'meta' => null,
                'errors' => null,
            ]);
        }

        // Verify all chunks arrived
        $received = $session->received_chunks ?? [];
        if (count($received) !== $session->total_chunks) {
            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [[
                    'code' => 'INCOMPLETE',
                    'message' => 'Not all chunks have been received. Got ' . count($received) . " of {$session->total_chunks}.",
                ]],
            ], 422);
        }

        // Mark assembling
        $session->update(['status' => 'assembling']);

        try {
            // Assemble: read each chunk from S3 and concatenate into a single source file
            $ext = pathinfo($session->original_filename, PATHINFO_EXTENSION) ?: 'mp4';
            $sourcePath = "videos/{$session->creator_profile_id}/{$uploadId}/source.{$ext}";

            // For single-chunk uploads (most common with small files), just copy
            if ($session->total_chunks === 1) {
                $chunkPath = "uploads/{$uploadId}/chunk-0";
                Storage::disk('s3')->copy($chunkPath, $sourcePath);
            } else {
                // Multi-chunk: download each chunk, concatenate, and upload
                $assembled = '';
                for ($i = 0; $i < $session->total_chunks; $i++) {
                    $chunkPath = "uploads/{$uploadId}/chunk-{$i}";
                    $assembled .= Storage::disk('s3')->get($chunkPath);
                }
                Storage::disk('s3')->put($sourcePath, $assembled);
                unset($assembled); // Free memory
            }

            // Clean up chunk files
            for ($i = 0; $i < $session->total_chunks; $i++) {
                Storage::disk('s3')->delete("uploads/{$uploadId}/chunk-{$i}");
            }

            // Generate a thumbnail URL using the S3 public URL
            $thumbnailUrl = null;
            $s3Bucket = config('filesystems.disks.s3.bucket');
            $s3Region = config('filesystems.disks.s3.region');
            $sourceUrl = "https://{$s3Bucket}.s3.{$s3Region}.amazonaws.com/{$sourcePath}";

            // Update the Video row
            DB::table('videos')->where('id', $session->video_id)->update([
                'status'       => 'ready',
                'source_path'  => $sourceUrl,
                'review_status'=> 'pending_review',
                'updated_at'   => now(),
            ]);

            // Mark upload session as completed
            $session->update(['status' => 'completed']);

            return response()->json([
                'data' => $this->formatSession($session->fresh()),
                'meta' => null,
                'errors' => null,
            ]);
        } catch (\Throwable $e) {
            // Mark as failed on any error
            $session->update(['status' => 'failed']);

            if ($session->video_id) {
                DB::table('videos')->where('id', $session->video_id)->update([
                    'status' => 'failed',
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'data' => null,
                'meta' => null,
                'errors' => [['code' => 'ASSEMBLE_FAILED', 'message' => 'Failed to assemble video: ' . $e->getMessage()]],
            ], 500);
        }
    }
}
