<?php

namespace App\Services\Generation;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the ffmpeg / ffprobe binaries. Arguments are passed
 * as arrays (no shell), so file names never need escaping.
 */
class FFmpeg
{
    private array $log = [];

    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private string $ffprobe = 'ffprobe',
        private int $timeout = 3600,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('autoposter.generation.ffmpeg'),
            config('autoposter.generation.ffprobe'),
            config('autoposter.generation.render_timeout'),
        );
    }

    public function duration(string $path): float
    {
        $process = new Process([
            $this->ffprobe, '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        $process->setTimeout(60)->mustRun();

        return (float) trim($process->getOutput());
    }

    /**
     * One scene: b-roll (looped clip, slow-zoom photo, or a plain
     * background) cut to the length of the scene's voiceover.
     */
    public function renderScene(?string $asset, ?string $assetType, string $audio, string $out, int $w, int $h): void
    {
        $seconds = $this->duration($audio);
        $fps = 30;
        $fit = "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},setsar=1";

        if ($asset && $assetType === 'video') {
            $input = ['-stream_loop', '-1', '-i', $asset];
            $filter = "{$fit},fps={$fps},format=yuv420p";
        } elseif ($asset) {
            // Ken Burns: upscale, then a slow centred zoom.
            $frames = (int) ceil($seconds * $fps);
            $input = ['-loop', '1', '-framerate', (string) $fps, '-i', $asset];
            $filter = 'scale='.($w * 2).':'.($h * 2).':force_original_aspect_ratio=increase,crop='.($w * 2).':'.($h * 2)
                .",zoompan=z='min(zoom+0.0007,1.2)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s={$w}x{$h}:fps={$fps}"
                .",setsar=1,format=yuv420p,trim=end_frame={$frames}";
        } else {
            $input = ['-f', 'lavfi', '-i', "color=c=0x111827:s={$w}x{$h}:r={$fps}"];
            $filter = 'format=yuv420p';
        }

        $this->run([
            $this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            ...$input,
            '-i', $audio,
            '-map', '0:v:0', '-map', '1:a:0',
            '-vf', $filter,
            '-t', number_format($seconds, 3, '.', ''),
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-r', (string) $fps,
            '-c:a', 'aac', '-b:a', '192k', '-ar', '44100', '-ac', '2',
            '-movflags', '+faststart',
            $out,
        ]);
    }

    /** Joins scene files that share codec settings, without re-encoding. */
    public function concat(array $segments, string $out): void
    {
        $list = $out.'.txt';
        file_put_contents($list, collect($segments)
            ->map(fn ($s) => "file '".str_replace("'", "'\\''", $s)."'")
            ->implode("\n"));

        $this->run([
            $this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $out,
        ]);
        @unlink($list);
    }

    /**
     * Final pass: burn in captions, lay a quiet music bed under the voice,
     * normalise loudness to YouTube's -14 LUFS.
     */
    public function finalize(string $in, ?string $srt, ?string $music, float $musicVolume, string $out, bool $vertical, ?string $fontPath): void
    {
        $args = [$this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error', '-i', $in];
        if ($music) {
            $args = [...$args, '-stream_loop', '-1', '-i', $music];
        }

        $video = '[0:v]null[v]';
        if ($srt) {
            $style = $vertical
                ? 'FontSize=13,Bold=1,Outline=2,Shadow=0,Alignment=2,MarginV=70'
                : 'FontSize=18,Bold=1,Outline=2,Shadow=0,Alignment=2,MarginV=22';
            $fontName = $fontPath ? pathinfo($fontPath, PATHINFO_FILENAME) : 'Sans';
            $fontsDir = $fontPath ? ':fontsdir='.$this->escapeFilterValue(dirname($fontPath)) : '';
            $video = '[0:v]subtitles='.$this->escapeFilterValue($srt).$fontsDir
                .":force_style='FontName=".str_replace('-', ' ', $fontName).",{$style}'[v]";
        }

        $audio = $music
            ? "[1:a]volume={$musicVolume}[bed];[0:a][bed]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,loudnorm=I=-14:TP=-1.5:LRA=11[a]"
            : '[0:a]loudnorm=I=-14:TP=-1.5:LRA=11[a]';

        $this->run([
            ...$args,
            '-filter_complex', "{$video};{$audio}",
            '-map', '[v]', '-map', '[a]',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
            '-movflags', '+faststart',
            $out,
        ]);
    }

    public function extractFrame(string $video, float $at, string $out): void
    {
        $this->run([
            $this->ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string) $at, '-i', $video, '-frames:v', '1', '-q:v', '2', $out,
        ]);
    }

    public function log(): string
    {
        return implode("\n", $this->log);
    }

    private function run(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout($this->timeout);
        $process->run();

        $this->log[] = '$ '.implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? "\"{$a}\"" : $a, $command));
        if ($err = trim($process->getErrorOutput())) {
            $this->log[] = $err;
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFmpeg failed: '.substr($process->getErrorOutput(), -1500));
        }
    }

    /** Escapes a path for use inside an ffmpeg filter argument. */
    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', ':', "'", ',', '[', ']', ';'], ['\\\\', '\\:', "\\'", '\\,', '\\[', '\\]', '\;'], $value);
    }
}
