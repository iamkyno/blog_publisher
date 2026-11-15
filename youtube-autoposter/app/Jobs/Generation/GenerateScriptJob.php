<?php

namespace App\Jobs\Generation;

use App\Enums\VideoStatus;
use App\Services\Generation\ScriptWriter;

class GenerateScriptJob extends GenerationJob
{
    public int $timeout = 600;

    public function handle(ScriptWriter $writer): void
    {
        $video = $this->video->fresh();
        $video->transitionTo(VideoStatus::Scripting, ['last_error' => null]);

        $result = $writer->write($video);

        $description = trim($result['description']);
        if ($result['sources']) {
            $description .= "\n\nSources\n".collect($result['sources'])->map(fn ($s) => "- {$s}")->implode("\n");
        }

        $video->update([
            'title' => mb_substr($result['title'], 0, 100),
            'thumbnail_text' => mb_substr($result['thumbnail_text'] ?? '', 0, 60) ?: null,
            'description' => $description,
            'tags' => array_values(array_slice($result['tags'], 0, 15)),
            'scenes' => array_map(fn ($s) => [
                'narration' => $s['narration'],
                'visual_query' => $s['visual_query'],
                'chapter_title' => $s['chapter_title'],
            ], $result['scenes']),
            'script' => collect($result['scenes'])->pluck('narration')->implode("\n\n"),
            // A fresh script invalidates everything rendered from the old one.
            'audio_path' => null,
            'captions_path' => null,
            'file_path' => null,
            'thumbnail_path' => null,
            'chapters' => null,
        ]);
    }
}
