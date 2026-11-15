<?php

namespace App\Jobs\Generation;

use App\Services\Generation\Timeline;

class GenerateCaptionsJob extends GenerationJob
{
    public int $timeout = 60;

    public function handle(): void
    {
        $video = $this->video->fresh();
        $scenes = $video->scenes ?? [];

        $relative = $this->relative($video, 'captions.srt');
        $this->disk()->put($relative, Timeline::srt($scenes, $video->is_short ? 4 : 7));

        $video->update([
            'captions_path' => $relative,
            'chapters' => $video->is_short ? null : (Timeline::chapters($scenes) ?: null),
        ]);
    }
}
