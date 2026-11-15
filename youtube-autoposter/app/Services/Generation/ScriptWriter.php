<?php

namespace App\Services\Generation;

use App\Models\Video;

interface ScriptWriter
{
    /**
     * @return array{
     *     title: string,
     *     thumbnail_text: string,
     *     description: string,
     *     tags: list<string>,
     *     scenes: list<array{narration: string, visual_query: string, chapter_title: string}>,
     *     sources: list<string>
     * }
     */
    public function write(Video $video): array;
}
