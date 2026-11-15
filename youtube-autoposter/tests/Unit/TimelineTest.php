<?php

namespace Tests\Unit;

use App\Services\Generation\Timeline;
use PHPUnit\Framework\TestCase;

class TimelineTest extends TestCase
{
    public function test_srt_cues_cover_each_scene_in_order(): void
    {
        $srt = Timeline::srt([
            ['narration' => 'One two three four five six', 'duration' => 3.0],
            ['narration' => 'Seven eight', 'duration' => 2.0],
        ], 3);

        $this->assertStringStartsWith("1\n00:00:00,000 --> ", $srt);
        $this->assertStringContainsString("One two three\n", $srt);
        $this->assertStringContainsString("3\n00:00:03,000 --> 00:00:05,000\nSeven eight", $srt);
    }

    public function test_chapters_start_at_zero_and_skip_short_ones(): void
    {
        $chapters = Timeline::chapters([
            ['chapter_title' => 'Intro', 'duration' => 20],
            ['chapter_title' => 'Rates', 'duration' => 4],
            // Starts 4s after "Rates", which would leave "Rates" under 10s: dropped.
            ['chapter_title' => 'Too close', 'duration' => 30],
            ['chapter_title' => '', 'duration' => 30],
            ['chapter_title' => 'What to do', 'duration' => 45],
        ]);

        $this->assertSame([
            ['time' => '0:00', 'title' => 'Intro'],
            ['time' => '0:20', 'title' => 'Rates'],
            ['time' => '1:24', 'title' => 'What to do'],
        ], $chapters);
    }

    public function test_fewer_than_three_chapters_returns_none(): void
    {
        $this->assertSame([], Timeline::chapters([
            ['chapter_title' => 'Intro', 'duration' => 60],
            ['chapter_title' => 'Main', 'duration' => 60],
        ]));
    }

    public function test_clock_format(): void
    {
        $this->assertSame('9:05', Timeline::clock(545.9));
        $this->assertSame('1:00:00', Timeline::clock(3600));
    }
}
