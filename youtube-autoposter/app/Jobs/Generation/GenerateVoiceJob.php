<?php

namespace App\Jobs\Generation;

use App\Enums\VideoStatus;
use App\Services\Generation\FFmpeg;
use App\Services\Generation\VoiceGenerator;

/**
 * One MP3 per scene, so each scene's length is known exactly. Scenes whose
 * narration hasn't changed since the last run keep their audio.
 */
class GenerateVoiceJob extends GenerationJob
{
    public function handle(VoiceGenerator $voice): void
    {
        $video = $this->video->fresh();
        $video->transitionTo(VideoStatus::Rendering);
        $ffmpeg = FFmpeg::fromConfig();

        $scenes = $video->scenes ?? [];
        foreach ($scenes as $i => &$scene) {
            $hash = sha1($scene['narration']);
            $existing = isset($scene['audio_path']) ? $this->disk()->path($scene['audio_path']) : null;

            if ($existing && is_file($existing) && ($scene['narration_hash'] ?? null) === $hash) {
                continue;
            }

            $relative = $this->relative($video, "voice/scene-{$i}.mp3");
            $this->disk()->put($relative, $voice->synthesize($scene['narration']));

            $scene['audio_path'] = $relative;
            $scene['narration_hash'] = $hash;
            $scene['duration'] = $ffmpeg->duration($this->disk()->path($relative));
        }
        unset($scene);

        $video->update([
            'scenes' => $scenes,
            'script' => collect($scenes)->pluck('narration')->implode("\n\n"),
        ]);
    }
}
