<?php

namespace App\Services\Generation;

use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * 1280×720 thumbnail: a darkened background frame with the title on top.
 * YouTube's limit is 2 MB; JPEG at quality 85 stays well under.
 */
class ThumbnailMaker
{
    public function make(string $background, string $title, string $out, ?string $fontPath): void
    {
        $image = ImageManager::gd()->read($background)->cover(1280, 720);
        $image->brightness(-35);

        // Left-aligned panel so the text reads at small sizes.
        $image->drawRectangle(0, 0, function ($rectangle) {
            $rectangle->size(1280, 720);
            $rectangle->background('rgba(0, 0, 0, 0.25)');
        });

        $text = mb_strtoupper(self::shorten($title));
        $size = mb_strlen($text) > 24 ? 84 : 110;

        $image->text($text, 70, 360, function (FontFactory $font) use ($fontPath, $size) {
            if ($fontPath && is_file($fontPath)) {
                $font->filename($fontPath);
            }
            $font->size($size);
            $font->color('#ffffff');
            $font->stroke('#000000', 4);
            $font->align('left');
            $font->valign('middle');
            $font->lineHeight(1.5);
            $font->wrap(1100);
        });

        $image->toJpeg(85)->save($out);
    }

    /**
     * Thumbnails read at a glance: at most 6 words. Used as a fallback when
     * there's no thumbnail_text; never ends on a dangling small word.
     */
    public static function shorten(string $text): string
    {
        $text = preg_replace('/\s*[\(\[].*?[\)\]]\s*/', ' ', $text);
        $main = preg_split('/\s*[:|–—]\s+|\s+-\s+/u', trim($text))[0] ?? $text;
        $words = preg_split('/\s+/', trim($main));

        if (count($words) <= 6) {
            return implode(' ', $words);
        }

        $words = array_slice($words, 0, 6);
        $dangling = ['a', 'an', 'the', 'on', 'of', 'to', 'in', 'for', 'and', 'or', 'with', 'your', 'at', 'by', 'from', 'is'];
        while (count($words) > 2 && in_array(mb_strtolower(end($words)), $dangling, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }
}
