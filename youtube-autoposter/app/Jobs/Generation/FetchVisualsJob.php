<?php

namespace App\Jobs\Generation;

use App\Services\Generation\StockMedia;
use Illuminate\Support\Facades\Http;

/**
 * B-roll per scene from Pexels (free licence). Scenes that already have an
 * asset — including ones you uploaded yourself — are left alone. Each
 * asset's source page is logged on the scene.
 */
class FetchVisualsJob extends GenerationJob
{
    public function handle(StockMedia $stock): void
    {
        $video = $this->video->fresh();
        $scenes = $video->scenes ?? [];
        $used = collect($scenes)->pluck('asset_source')->filter()->all();

        foreach ($scenes as $i => &$scene) {
            if (! empty($scene['asset_path']) && $this->disk()->exists($scene['asset_path'])) {
                continue;
            }

            $found = $stock->find($scene['visual_query'], $video->is_short);
            if (! $found || in_array($found['source'], $used, true)) {
                // Fall back to the broader topic so no scene goes blank.
                $found = $stock->find((string) $video->topic, $video->is_short) ?? $found;
            }
            if (! $found) {
                $scene['asset_path'] = null;
                $scene['asset_type'] = null;

                continue;
            }

            $ext = $found['type'] === 'video' ? 'mp4' : 'jpg';
            $relative = $this->relative($video, "assets/scene-{$i}.{$ext}");
            $local = $this->localPath($video, "assets/scene-{$i}.{$ext}");
            Http::timeout(300)->sink($local)->get($found['url'])->throw();
            clearstatcache(true, $local);
            if (! is_file($local) || filesize($local) === 0) {
                throw new \RuntimeException("Downloaded b-roll for scene {$i} is empty: {$found['url']}");
            }

            $scene['asset_path'] = $relative;
            $scene['asset_type'] = $found['type'];
            $scene['asset_source'] = $found['source'];
            $scene['asset_licence'] = 'Pexels License';
            $scene['asset_credit'] = $found['credit'];
            $used[] = $found['source'];
        }
        unset($scene);

        $video->update(['scenes' => $scenes]);
    }
}
