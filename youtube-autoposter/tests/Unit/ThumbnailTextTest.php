<?php

namespace Tests\Unit;

use App\Services\Generation\ThumbnailMaker;
use PHPUnit\Framework\TestCase;

class ThumbnailTextTest extends TestCase
{
    public function test_short_text_is_kept_whole(): void
    {
        $this->assertSame('R240 a month back', ThumbnailMaker::shorten('R240 a month back'));
    }

    public function test_long_title_is_cut_without_dangling_words(): void
    {
        $this->assertSame('What a 25bp SARB cut saves', ThumbnailMaker::shorten('What a 25bp SARB cut saves on a R1.5m bond'));
        $this->assertSame('Bond repayments after the cut', ThumbnailMaker::shorten('Bond repayments after the cut: the real numbers'));
    }
}
