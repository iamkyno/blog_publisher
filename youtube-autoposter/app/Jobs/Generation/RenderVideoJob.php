<?php

namespace App\Jobs\Generation;

use App\Models\Video;
use App\Services\Generation\FFmpeg;

/**
 * CPU-heavy: runs on its own queue (`--queue=render`) on a VPS with FFmpeg.
 */
class RenderVideoJob extends GenerationJob
{
    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(public Video $video)
    {
        parent::__construct($video);
        $this->onQueue('render');
    }

    public function handle(): void
    {
        $video = $this->video->fresh();
        [$w, $h] = $this->frameSize($video);
        $ffmpeg = FFmpeg::fromConfig();
        $disk = $this->disk();

        $segments = [];
        foreach ($video->scenes ?? [] as $i => $scene) {
            $out = $this->localPath($video, "render/scene-{$i}.mp4");
            $ffmpeg->renderScene(
                ! empty($scene['asset_path']) ? $disk->path($scene['asset_path']) : null,
                $scene['asset_type'] ?? null,
                $disk->path($scene['audio_path']),
                $out, $w, $h,
            );
            $segments[] = $out;
        }

        $joined = $this->localPath($video, 'render/joined.mp4');
        $ffmpeg->concat($segments, $joined);

        $music = config('autoposter.generation.music_path');
        $final = $this->relative($video, 'final.mp4');
        $ffmpeg->finalize(
            $joined,
            $video->captions_path ? $disk->path($video->captions_path) : null,
            $music && $disk->exists($music) ? $disk->path($music) : null,
            config('autoposter.generation.music_volume'),
            $this->localPath($video, 'final.mp4'),
            $video->is_short,
            config('autoposter.generation.font_path'),
        );

        foreach ([...$segments, $joined] as $tmp) {
            @unlink($tmp);
        }

        $video->update(['file_path' => $final, 'render_log' => mb_substr($ffmpeg->log(), -20000)]);
    }
}
