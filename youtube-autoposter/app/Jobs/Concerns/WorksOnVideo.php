<?php

namespace App\Jobs\Concerns;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;

trait WorksOnVideo
{
    protected function disk()
    {
        return Storage::disk(config('autoposter.disk'));
    }

    /** Absolute path on the (local) media disk; creates the directory. */
    protected function localPath(Video $video, string $relative): string
    {
        $path = $this->disk()->path($video->mediaDir().'/'.$relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        return $path;
    }

    protected function relative(Video $video, string $relative): string
    {
        return $video->mediaDir().'/'.$relative;
    }

    /** [width, height] for the video's format. */
    protected function frameSize(Video $video): array
    {
        return $video->is_short ? [1080, 1920] : [1920, 1080];
    }
}
