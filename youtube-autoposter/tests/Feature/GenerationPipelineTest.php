<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\Generation\FFmpeg;
use App\Services\Generation\Pipeline;
use App\Services\Generation\ScriptWriter;
use App\Services\Generation\StockMedia;
use App\Services\Generation\VoiceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The whole generation chain with real FFmpeg; only the paid APIs
 * (Claude, TTS, Pexels) are faked.
 */
class GenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        Storage::fake('local');
        $this->fixtures = sys_get_temp_dir().'/autoposter-fixtures-'.getmypid();
        @mkdir($this->fixtures);

        (new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=30', '-t', '2', '-pix_fmt', 'yuv420p', "{$this->fixtures}/clip.mp4"]))->mustRun();
        (new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'mandelbrot=size=1600x900', '-frames:v', '1', "{$this->fixtures}/photo.jpg"]))->mustRun();

        $this->app->instance(ScriptWriter::class, new class implements ScriptWriter
        {
            public function write(Video $video): array
            {
                return [
                    'title' => 'What a 25 basis point cut saves on a R1.5m bond',
                    'thumbnail_text' => 'R240 a month back',
                    'description' => 'We run the numbers.',
                    'tags' => ['bond', 'repo rate', 'sarb'],
                    'scenes' => [
                        ['narration' => 'The Reserve Bank cut the repo rate by a quarter point.', 'visual_query' => 'bank building', 'chapter_title' => 'Intro'],
                        ['narration' => 'On a one and a half million rand bond, that is about two hundred and forty rand a month.', 'visual_query' => 'calculator', 'chapter_title' => ''],
                        ['narration' => 'Keep paying the old amount and you finish years sooner.', 'visual_query' => 'nothing matches this', 'chapter_title' => 'What to do'],
                    ],
                    'sources' => ['SARB MPC statement'],
                ];
            }
        });

        $fixtures = $this->fixtures;
        $this->app->instance(VoiceGenerator::class, new class($fixtures) extends VoiceGenerator
        {
            public function __construct(private string $dir) {}

            public function synthesize(string $text): string
            {
                $seconds = max(1, str_word_count($text) * 0.15);
                $out = "{$this->dir}/".sha1($text).'.mp3';
                (new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=220:sample_rate=44100', '-t', (string) $seconds, '-c:a', 'libmp3lame', $out]))->mustRun();

                return file_get_contents($out);
            }
        });

        $this->app->instance(StockMedia::class, new class extends StockMedia
        {
            public function find(string $query, bool $vertical): ?array
            {
                return match ($query) {
                    'bank building' => ['url' => 'https://media.test/clip.mp4', 'type' => 'video', 'source' => 'https://pexels.test/v/1', 'credit' => 'Video by A on Pexels'],
                    'calculator' => ['url' => 'https://media.test/photo.jpg', 'type' => 'photo', 'source' => 'https://pexels.test/p/2', 'credit' => 'Photo by B on Pexels'],
                    default => null, // and the topic fallback finds nothing either
                };
            }
        });

        Http::fake([
            'media.test/clip.mp4' => fn () => Http::response(file_get_contents("{$this->fixtures}/clip.mp4")),
            'media.test/photo.jpg' => fn () => Http::response(file_get_contents("{$this->fixtures}/photo.jpg")),
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->fixtures}/*") ?: []);
        @rmdir($this->fixtures);
        parent::tearDown();
    }

    public function test_topic_becomes_a_rendered_video_waiting_for_review(): void
    {
        $video = Video::factory()->create(['topic' => 'SARB rate cut', 'is_short' => false]);

        Pipeline::start($video); // sync queue in tests: runs the whole chain

        $video->refresh();
        $this->assertSame(VideoStatus::Review, $video->status, (string) $video->last_error);
        $this->assertSame('What a 25 basis point cut saves on a R1.5m bond', $video->title);
        $this->assertStringContainsString("Sources\n- SARB MPC statement", $video->description);

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($video->file_path));
        $this->assertTrue($disk->exists($video->thumbnail_path));
        $this->assertStringContainsString('-->', $disk->get($video->captions_path));

        // Scenes carry their asset source for the licence log.
        $this->assertSame('https://pexels.test/v/1', $video->scenes[0]['asset_source']);
        $this->assertSame('photo', $video->scenes[1]['asset_type']);
        $this->assertNull($video->scenes[2]['asset_path']); // plain background

        $expected = array_sum(array_column($video->scenes, 'duration'));
        $actual = FFmpeg::fromConfig()->duration($disk->path($video->file_path));
        $this->assertEqualsWithDelta($expected, $actual, 0.5);

        [$w, $h] = $this->dimensions($disk->path($video->file_path));
        $this->assertSame([1920, 1080], [$w, $h]);
        $this->assertSame([1280, 720], array_slice(getimagesize($disk->path($video->thumbnail_path)), 0, 2));
    }

    public function test_short_renders_vertical(): void
    {
        $video = Video::factory()->create(['topic' => 'SARB rate cut', 'is_short' => true]);

        Pipeline::start($video);

        $video->refresh();
        $this->assertSame(VideoStatus::Review, $video->status, (string) $video->last_error);
        $this->assertSame([1080, 1920], $this->dimensions(Storage::disk('local')->path($video->file_path)));
        $this->assertNull($video->chapters);
    }

    public function test_regenerating_a_scene_only_revoices_that_scene(): void
    {
        $video = Video::factory()->create(['topic' => 'SARB rate cut']);
        Pipeline::start($video);
        $before = $video->fresh()->scenes;

        Pipeline::regenerateScene($video->fresh(), 1, 'A shorter line.');

        $after = $video->fresh();
        $this->assertSame(VideoStatus::Review, $after->status, (string) $after->last_error);
        $this->assertSame('A shorter line.', $after->scenes[1]['narration']);
        $this->assertNotEquals($before[1]['duration'], $after->scenes[1]['duration']);
        $this->assertSame($before[0]['narration_hash'], $after->scenes[0]['narration_hash']);
        $this->assertEquals($before[0]['duration'], $after->scenes[0]['duration']);
    }

    private function dimensions(string $file): array
    {
        $p = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $file]);
        $p->mustRun();

        return array_map('intval', explode(',', trim($p->getOutput())));
    }
}
