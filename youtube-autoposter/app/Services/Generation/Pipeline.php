<?php

namespace App\Services\Generation;

use App\Jobs\Generation\FetchVisualsJob;
use App\Jobs\Generation\GenerateCaptionsJob;
use App\Jobs\Generation\GenerateScriptJob;
use App\Jobs\Generation\GenerateVoiceJob;
use App\Jobs\Generation\MakeThumbnailJob;
use App\Jobs\Generation\RenderVideoJob;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Throwable;

/**
 * Generation runs as a chain: each step only after the last succeeds.
 * Start from a later step to redo part of a video after edits.
 */
class Pipeline
{
    public const STEPS = [
        'script' => GenerateScriptJob::class,
        'voice' => GenerateVoiceJob::class,
        'visuals' => FetchVisualsJob::class,
        'captions' => GenerateCaptionsJob::class,
        'render' => RenderVideoJob::class,
        'thumbnail' => MakeThumbnailJob::class,
    ];

    public static function start(Video $video, string $from = 'script'): void
    {
        if (! array_key_exists($from, self::STEPS)) {
            throw new InvalidArgumentException("Unknown step [{$from}].");
        }

        $steps = array_slice(self::STEPS, array_search($from, array_keys(self::STEPS), true));
        $jobs = array_map(fn ($class) => new $class($video), array_values($steps));
        $id = $video->id;

        Bus::chain($jobs)
            ->catch(function (Throwable $e) use ($id) {
                Video::find($id)?->fail($e);
            })
            ->dispatch();
    }

    /** Re-voice and re-fetch one scene, then rebuild the video. */
    public static function regenerateScene(Video $video, int $index, ?string $narration = null, ?string $visualQuery = null): void
    {
        $scenes = $video->scenes;
        if (! isset($scenes[$index])) {
            throw new InvalidArgumentException("Scene {$index} does not exist.");
        }

        if ($narration !== null) {
            $scenes[$index]['narration'] = $narration;
        }
        if ($visualQuery !== null) {
            $scenes[$index]['visual_query'] = $visualQuery;
        }
        // Fetch a new clip, unless you uploaded this scene's asset yourself.
        if (($scenes[$index]['asset_source'] ?? null) !== 'upload') {
            $scenes[$index]['asset_path'] = null;
        }
        unset($scenes[$index]['narration_hash']); // force a new voiceover

        $video->update(['scenes' => $scenes]);
        self::start($video, 'voice');
    }
}
