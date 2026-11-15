<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Streams a video's generated files to the admin panel (supports seeking). */
class VideoMediaController extends Controller
{
    public function __invoke(Video $video, string $kind, ?int $scene = null): BinaryFileResponse
    {
        $relative = match ($kind) {
            'video' => $video->file_path,
            'thumbnail' => $video->thumbnail_path,
            'captions' => $video->captions_path,
            'scene-audio' => $video->scenes[$scene]['audio_path'] ?? null,
            'scene-asset' => $video->scenes[$scene]['asset_path'] ?? null,
            default => null,
        };

        $disk = Storage::disk(config('autoposter.disk'));
        abort_unless($relative && $disk->exists($relative), 404);

        return response()->file($disk->path($relative), ['Cache-Control' => 'private, max-age=60']);
    }
}
