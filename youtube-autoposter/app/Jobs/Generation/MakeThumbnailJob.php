<?php

namespace App\Jobs\Generation;

use App\Enums\VideoStatus;
use App\Notifications\AutoposterAlert;
use App\Services\Generation\FFmpeg;
use App\Services\Generation\ThumbnailMaker;

/** Last step of generation: hands the video to you for review. */
class MakeThumbnailJob extends GenerationJob
{
    public int $timeout = 120;

    public function handle(ThumbnailMaker $maker): void
    {
        $video = $this->video->fresh();
        $disk = $this->disk();

        $photo = collect($video->scenes ?? [])->first(fn ($s) => ($s['asset_type'] ?? null) === 'photo' && ! empty($s['asset_path']));
        if ($photo) {
            $background = $disk->path($photo['asset_path']);
        } else {
            $background = $this->localPath($video, 'render/thumb-frame.jpg');
            FFmpeg::fromConfig()->extractFrame($disk->path($video->file_path), 2.0, $background);
        }

        $relative = $this->relative($video, 'thumbnail.jpg');
        $maker->make($background, (string) ($video->thumbnail_text ?: $video->title), $this->localPath($video, 'thumbnail.jpg'), config('autoposter.generation.font_path'));

        $video->update(['thumbnail_path' => $relative]);
        $video->transitionTo(VideoStatus::Review);

        AutoposterAlert::send('review', "Video #{$video->id} \"{$video->title}\" is ready for review.", $video);
    }
}
