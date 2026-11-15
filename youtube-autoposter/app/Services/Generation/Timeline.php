<?php

namespace App\Services\Generation;

/**
 * Turns scenes with measured voiceover lengths into captions (.srt) and
 * YouTube chapter markers.
 */
class Timeline
{
    /**
     * @param  list<array{narration: string, duration: float}>  $scenes
     */
    public static function srt(array $scenes, int $wordsPerCaption = 10): string
    {
        $cues = [];
        $offset = 0.0;

        foreach ($scenes as $scene) {
            $words = preg_split('/\s+/', trim($scene['narration'])) ?: [];
            $chunks = array_chunk($words, max(1, $wordsPerCaption));
            $totalChars = max(1, mb_strlen(implode(' ', $words)));
            $cursor = $offset;

            foreach ($chunks as $chunk) {
                $text = implode(' ', $chunk);
                // Time on screen is proportional to the characters spoken.
                $length = $scene['duration'] * (mb_strlen($text) + 1) / ($totalChars + count($chunks));
                $cues[] = [$cursor, $cursor + $length, $text];
                $cursor += $length;
            }

            $offset += $scene['duration'];
        }

        $out = [];
        foreach ($cues as $i => [$start, $end, $text]) {
            $out[] = ($i + 1)."\n".self::srtTime($start).' --> '.self::srtTime($end)."\n".wordwrap($text, 42)."\n";
        }

        return implode("\n", $out);
    }

    /**
     * YouTube only shows chapters when the first starts at 0:00, there are
     * at least 3, and each lasts 10 seconds or more.
     *
     * @param  list<array{chapter_title?: string, duration: float}>  $scenes
     * @return list<array{time: string, title: string}>
     */
    public static function chapters(array $scenes): array
    {
        $chapters = [];
        $offset = 0.0;

        foreach ($scenes as $i => $scene) {
            $title = trim($scene['chapter_title'] ?? '');
            if ($i === 0 && $title === '') {
                $title = 'Intro';
            }
            if ($title !== '') {
                $last = end($chapters);
                if ($last && $offset - $last['start'] < 10) {
                    // Too short: fold into the previous chapter.
                } else {
                    $chapters[] = ['start' => $offset, 'title' => $title];
                }
            }
            $offset += $scene['duration'];
        }

        // The last chapter must also run 10 seconds or more.
        if (count($chapters) > 1 && $offset - end($chapters)['start'] < 10) {
            array_pop($chapters);
        }

        if (count($chapters) < 3) {
            return [];
        }

        return array_map(fn ($c) => ['time' => self::clock($c['start']), 'title' => $c['title']], $chapters);
    }

    public static function srtTime(float $seconds): string
    {
        $ms = (int) round($seconds * 1000);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, $ms % 1000);
    }

    public static function clock(float $seconds): string
    {
        $s = (int) floor($seconds);

        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s, 60) % 60, $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
