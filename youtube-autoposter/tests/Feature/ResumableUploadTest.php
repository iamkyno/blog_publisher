<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Jobs\CheckProcessingJob;
use App\Jobs\UploadVideoJob;
use App\Models\Video;
use App\Services\YouTubeClient;
use Google\Client as GoogleClient;
use Google\Service\YouTube;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Runs the real resumable upload code against a fake Google API. */
class ResumableUploadTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function youtubeWith(array $responses): YouTube
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $client = new GoogleClient;
        $client->setHttpClient(new Guzzle(['handler' => $stack]));
        $client->setAccessToken(['access_token' => 'test-token', 'expires_in' => 3600, 'created' => time()]);

        return new YouTube($client);
    }

    public function test_uploads_private_with_publish_at_and_ai_disclosure(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['autoposter.audit_passed' => true, 'autoposter.chunk_size' => 256 * 1024]);
        Storage::disk('local')->put('videos/1/final.mp4', str_repeat('x', 300 * 1024)); // two chunks
        Storage::disk('local')->put('videos/1/thumbnail.jpg', 'jpeg-bytes');

        $video = Video::factory()->queued()->create([
            'status' => VideoStatus::Uploading,
            'file_path' => 'videos/1/final.mp4',
            'thumbnail_path' => 'videos/1/thumbnail.jpg',
            'chapters' => [['time' => '0:00', 'title' => 'Intro'], ['time' => '1:10', 'title' => 'Rates'], ['time' => '3:00', 'title' => 'What to do']],
        ]);

        $yt = $this->youtubeWith([
            new Response(200, ['Location' => 'https://upload.example/session-1']),          // start session
            new Response(308, ['Range' => 'bytes=0-262143']),                                // chunk 1
            new Response(200, [], json_encode(['id' => 'yt-new-id', 'kind' => 'youtube#video'])), // chunk 2
            new Response(200, [], json_encode(['items' => []])),                             // thumbnails.set
        ]);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new UploadVideoJob($video))->handle(app(YouTubeClient::class));

        $video->refresh();
        $this->assertSame('yt-new-id', $video->youtube_video_id);
        $this->assertSame(VideoStatus::Processing, $video->status);
        Queue::assertPushed(CheckProcessingJob::class);

        $this->assertCount(4, $this->history);
        $start = $this->history[0]['request'];
        $this->assertStringContainsString('uploadType=resumable', (string) $start->getUri());
        $meta = json_decode((string) $start->getBody(), true);
        $this->assertSame('private', $meta['status']['privacyStatus']);
        $this->assertTrue($meta['status']['containsSyntheticMedia']);
        $this->assertFalse($meta['status']['selfDeclaredMadeForKids']);
        $this->assertSame($video->publish_at->utc()->toIso8601ZuluString(), $meta['status']['publishAt']);
        $this->assertStringContainsString("0:00 Intro\n1:10 Rates", $meta['snippet']['description']);

        $this->assertSame(256 * 1024, strlen((string) $this->history[1]['request']->getBody()));
        $this->assertSame(44 * 1024, strlen((string) $this->history[2]['request']->getBody()));
        $this->assertStringContainsString('/thumbnails/set', (string) $this->history[3]['request']->getUri());
    }

    public function test_before_audit_uploads_private_without_schedule(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('videos/1/final.mp4', 'tiny');
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Uploading, 'file_path' => 'videos/1/final.mp4']);

        $yt = $this->youtubeWith([
            new Response(200, ['Location' => 'https://upload.example/session-1']),
            new Response(200, [], json_encode(['id' => 'yt-private'])),
        ]);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new UploadVideoJob($video))->handle(app(YouTubeClient::class));

        $meta = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('private', $meta['status']['privacyStatus']);
        $this->assertArrayNotHasKey('publishAt', $meta['status']);
    }

    public function test_quota_error_pauses_until_reset(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('videos/1/final.mp4', 'tiny');
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Uploading, 'file_path' => 'videos/1/final.mp4']);

        $yt = $this->youtubeWith([
            new Response(403, ['Content-Type' => 'application/json'], json_encode(['error' => [
                'code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded', 'domain' => 'youtube.quota']],
            ]])),
        ]);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new UploadVideoJob($video))->handle(app(YouTubeClient::class));

        $this->assertSame(VideoStatus::Queued, $video->fresh()->status);
        $this->assertNotNull(cache(UploadVideoJob::QUOTA_LOCK));
    }
}
