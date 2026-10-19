<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('videos:extract-thumbnails', function () {
    $videos = \App\Models\Video::whereNotNull('source_path')->get();
    $this->info("Found {$videos->count()} videos to process...");

    $successCount = 0;
    foreach ($videos as $video) {
        $this->line("Processing video #{$video->id} ({$video->title})...");
        $thumbPath = \App\Services\Video\ThumbnailService::extractFirstFrame($video->source_path, $video->id);
        if ($thumbPath) {
            $video->thumbnail_path = $thumbPath;
            $video->save();
            $this->info("  -> Saved thumbnail: {$thumbPath}");
            $successCount++;
        } else {
            $this->error("  -> Failed to extract thumbnail for video #{$video->id}");
        }
    }

    $this->info("Completed! Successfully extracted {$successCount} of {$videos->count()} thumbnails.");
})->purpose('Extract first-shot thumbnails from video source files and update database');

