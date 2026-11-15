<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Finds a free-licence clip (or photo, as a fallback) on Pexels for a scene.
 * Every asset's source page is returned so it can be logged and credited.
 */
class StockMedia
{
    /**
     * @return array{url: string, type: 'video'|'photo', source: string, credit: string}|null
     */
    public function find(string $query, bool $vertical): ?array
    {
        $key = config('services.pexels.api_key') ?: throw new RuntimeException('PEXELS_API_KEY is not set.');
        $orientation = $vertical ? 'portrait' : 'landscape';

        $videos = Http::withHeaders(['Authorization' => $key])->timeout(30)
            ->get('https://api.pexels.com/videos/search', [
                'query' => $query, 'orientation' => $orientation, 'size' => 'medium', 'per_page' => 10,
            ]);

        if ($videos->ok()) {
            foreach ($videos->json('videos', []) as $video) {
                $file = $this->bestFile($video['video_files'] ?? [], $vertical);
                if ($file) {
                    return [
                        'url' => $file['link'],
                        'type' => 'video',
                        'source' => $video['url'],
                        'credit' => 'Video by '.($video['user']['name'] ?? 'unknown').' on Pexels',
                    ];
                }
            }
        }

        $photos = Http::withHeaders(['Authorization' => $key])->timeout(30)
            ->get('https://api.pexels.com/v1/search', [
                'query' => $query, 'orientation' => $orientation, 'per_page' => 5,
            ]);

        if ($photos->ok() && ($photo = $photos->json('photos.0'))) {
            return [
                'url' => $photo['src']['large2x'] ?? $photo['src']['original'],
                'type' => 'photo',
                'source' => $photo['url'],
                'credit' => 'Photo by '.($photo['photographer'] ?? 'unknown').' on Pexels',
            ];
        }

        return null;
    }

    /** Smallest MP4 that still covers 1080p in the needed direction. */
    private function bestFile(array $files, bool $vertical): ?array
    {
        return collect($files)
            ->filter(fn ($f) => ($f['file_type'] ?? '') === 'video/mp4')
            ->filter(fn ($f) => $vertical ? ($f['height'] ?? 0) >= 1920 || ($f['width'] ?? 0) >= 1080 : ($f['width'] ?? 0) >= 1920)
            ->sortBy('width')
            ->first()
            ?? collect($files)->where('file_type', 'video/mp4')->sortByDesc('width')->first();
    }
}
