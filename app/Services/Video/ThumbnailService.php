<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ThumbnailService
{
    /**
     * Extract the first frame from a video URL and save it as a local JPEG thumbnail.
     *
     * @param string $sourceUrl Remote or local video URL
     * @param int|string $videoId Video ID
     * @param int $preferredTimeMs Timestamp in milliseconds to grab frame (default 500ms)
     * @return string|null The relative public path or null on failure
     */
    public static function extractFirstFrame(string $sourceUrl, $videoId, int $preferredTimeMs = 500): ?string
    {
        try {
            $storageDir = public_path('storage/thumbnails');
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            $fileName = "video_{$videoId}.jpg";
            $outputPath = "{$storageDir}/{$fileName}";
            $scriptPath = base_path('scripts/extract_thumbnail.py');

            // Find python binary
            $pythonBin = 'python';

            $process = new Process([$pythonBin, $scriptPath, $sourceUrl, $outputPath, (string)$preferredTimeMs]);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful() && file_exists($outputPath) && filesize($outputPath) > 0) {
                return "/storage/thumbnails/{$fileName}";
            }

            Log::warning("Thumbnail extraction failed for video {$videoId}: " . $process->getErrorOutput());
            return null;
        } catch (\Throwable $e) {
            Log::error("Error in ThumbnailService::extractFirstFrame for video {$videoId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Save an uploaded image as the video thumbnail.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int|string $videoId
     * @return string|null
     */
    public static function saveUploadedThumbnail($file, $videoId): ?string
    {
        try {
            $storageDir = public_path('storage/thumbnails');
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            $fileName = "video_{$videoId}.jpg";
            $outputPath = "{$storageDir}/{$fileName}";

            $file->move($storageDir, $fileName);
            return "/storage/thumbnails/{$fileName}";
        } catch (\Throwable $e) {
            Log::error("Error in ThumbnailService::saveUploadedThumbnail for video {$videoId}: " . $e->getMessage());
            return null;
        }
    }
}
