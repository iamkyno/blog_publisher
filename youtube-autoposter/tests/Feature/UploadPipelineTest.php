<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Exceptions\YouTubeAuthException;
use App\Jobs\CheckProcessingJob;
use App\Jobs\UploadVideoJob;
use App\Models\Video;
use App\Models\YoutubeAccount;
use App\Services\YouTubeClient;
use Google\Service\YouTube;
use Google\Service\YouTube\Resource\Thumbnails;
use Google\Service\YouTube\Resource\Videos;
use Google\Service\YouTube\Video as YouTubeVideo;
use Google\Service\YouTube\VideoListResponse;
use Google\Service\YouTube\VideoProcessingDetails;
use Google\Service\YouTube\VideoStatus as YouTubeVideoStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class UploadPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_claims_only_due_videos_on_connected_channels(): void
    {
        Queue::fake();

        $due = Video::factory()->queued()->create();
        $later = Video::factory()->queued()->create(['publish_at' => now()->addDays(3)]);
        $disconnected = Video::factory()->queued()->create([
            'youtube_account_id' => YoutubeAccount::factory()->disconnected(),
        ]);
        $inReview = Video::factory()->create(['status' => VideoStatus::Review, 'publish_at' => now()->addHour()]);

        $this->artisan('autoposter:dispatch-uploads')->assertSuccessful();

        $this->assertSame(VideoStatus::Uploading, $due->fresh()->status);
        $this->assertSame(1, $due->fresh()->attempts);
        $this->assertSame(VideoStatus::Queued, $later->fresh()->status);
        $this->assertSame(VideoStatus::Queued, $disconnected->fresh()->status);
        $this->assertSame(VideoStatus::Review, $inReview->fresh()->status);
        Queue::assertPushed(UploadVideoJob::class, 1);
        Queue::assertPushedOn('uploads', UploadVideoJob::class);

        // A second run doesn't queue it twice.
        $this->artisan('autoposter:dispatch-uploads');
        Queue::assertPushed(UploadVideoJob::class, 1);
    }

    public function test_dispatcher_waits_while_quota_is_exhausted(): void
    {
        Queue::fake();
        Cache::put(UploadVideoJob::QUOTA_LOCK, now()->addHour()->toIso8601String(), 3600);
        $video = Video::factory()->queued()->create();

        $this->artisan('autoposter:dispatch-uploads');

        $this->assertSame(VideoStatus::Queued, $video->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_after_insert_does_not_upload_twice(): void
    {
        Queue::fake();
        $video = Video::factory()->queued()->create([
            'status' => VideoStatus::Uploading,
            'youtube_video_id' => 'abc123',
        ]);

        $yt = Mockery::mock(YouTube::class);
        $yt->videos = Mockery::mock(Videos::class);
        $yt->videos->shouldNotReceive('insert');
        $yt->thumbnails = Mockery::mock(Thumbnails::class);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new UploadVideoJob($video))->handle(app(YouTubeClient::class));

        $this->assertSame(VideoStatus::Processing, $video->fresh()->status);
        Queue::assertPushed(CheckProcessingJob::class);
    }

    public function test_disconnected_channel_pauses_instead_of_failing(): void
    {
        Queue::fake();
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Uploading]);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andThrow(new YouTubeAuthException('invalid_grant'));

        (new UploadVideoJob($video))->handle(app(YouTubeClient::class));

        $this->assertSame(VideoStatus::Queued, $video->fresh()->status);
        $this->assertSame('invalid_grant', $video->fresh()->last_error);
    }

    public function test_processing_check_moves_video_to_scheduled(): void
    {
        Queue::fake();
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Processing, 'youtube_video_id' => 'abc123']);

        $item = new YouTubeVideo;
        $item->setStatus(new YouTubeVideoStatus(['uploadStatus' => 'processed', 'privacyStatus' => 'private']));
        $item->setProcessingDetails(new VideoProcessingDetails(['processingStatus' => 'succeeded']));
        $list = new VideoListResponse;
        $list->setItems([$item]);

        $yt = Mockery::mock(YouTube::class);
        $yt->videos = Mockery::mock(Videos::class);
        $yt->videos->shouldReceive('listVideos')->with('status,processingDetails', ['id' => 'abc123'])->andReturn($list);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new CheckProcessingJob($video))->handle(app(YouTubeClient::class));

        $this->assertSame(VideoStatus::Scheduled, $video->fresh()->status);
        Queue::assertNotPushed(CheckProcessingJob::class);
    }

    public function test_processing_check_polls_again_while_processing(): void
    {
        Queue::fake();
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Processing, 'youtube_video_id' => 'abc123']);

        $item = new YouTubeVideo;
        $item->setStatus(new YouTubeVideoStatus(['uploadStatus' => 'uploaded']));
        $item->setProcessingDetails(new VideoProcessingDetails(['processingStatus' => 'processing']));
        $list = new VideoListResponse;
        $list->setItems([$item]);

        $yt = Mockery::mock(YouTube::class);
        $yt->videos = Mockery::mock(Videos::class);
        $yt->videos->shouldReceive('listVideos')->andReturn($list);
        $this->mock(YouTubeClient::class)->shouldReceive('for')->andReturn($yt);

        (new CheckProcessingJob($video))->handle(app(YouTubeClient::class));

        $this->assertSame(VideoStatus::Processing, $video->fresh()->status);
        Queue::assertPushed(CheckProcessingJob::class, fn ($job) => $job->check === 2);
    }

    public function test_approve_queues_video_with_active_channel(): void
    {
        $account = YoutubeAccount::factory()->create();
        $video = Video::factory()->create(['status' => VideoStatus::Review, 'publish_at' => now()->addDay()]);

        $video->approve();

        $this->assertSame(VideoStatus::Queued, $video->fresh()->status);
        $this->assertNotNull($video->fresh()->approved_at);
        $this->assertSame($account->id, $video->fresh()->youtube_account_id);
    }

    public function test_refresh_token_is_encrypted_at_rest(): void
    {
        $account = YoutubeAccount::factory()->create(['refresh_token' => 'secret-refresh']);

        $raw = \DB::table('youtube_accounts')->where('id', $account->id)->value('refresh_token');
        $this->assertNotSame('secret-refresh', $raw);
        $this->assertSame('secret-refresh', $account->fresh()->refresh_token);
    }

    public function test_mark_published_only_runs_after_audit(): void
    {
        $video = Video::factory()->queued()->create(['status' => VideoStatus::Scheduled, 'publish_at' => now()->subMinute()]);

        $this->artisan('autoposter:mark-published');
        $this->assertSame(VideoStatus::Scheduled, $video->fresh()->status);

        config(['autoposter.audit_passed' => true]);
        $this->artisan('autoposter:mark-published');
        $this->assertSame(VideoStatus::Published, $video->fresh()->status);
    }
}
