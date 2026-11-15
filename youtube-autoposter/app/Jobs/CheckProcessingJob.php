<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Services\YouTubeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Polls YouTube every 2 minutes until processing finishes. */
class CheckProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_CHECKS = 90; // 3 hours

    public int $tries = 3;

    public array $backoff = [60, 120, 300];

    public function __construct(public Video $video, public int $check = 1)
    {
        $this->onQueue('uploads');
    }

    public function handle(YouTubeClient $youtube): void
    {
        $video = $this->video->fresh();
        if ($video->status !== VideoStatus::Processing) {
            return;
        }

        $yt = $youtube->for($video->account);
        $item = $yt->videos->listVideos('status,processingDetails', ['id' => $video->youtube_video_id])->getItems()[0] ?? null;

        if (! $item) {
            $video->fail("YouTube no longer has video {$video->youtube_video_id} (deleted or rejected).");

            return;
        }

        $processing = $item->getProcessingDetails()?->getProcessingStatus();
        $upload = $item->getStatus()?->getUploadStatus();

        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || $processing === 'failed') {
            $reason = $item->getStatus()?->getFailureReason() ?? $item->getStatus()?->getRejectionReason() ?? $upload;
            $video->fail("YouTube processing failed: {$reason}");

            return;
        }

        if ($processing === 'succeeded' || $upload === 'processed') {
            // Public already if publishAt was in the past or not set.
            $public = $item->getStatus()?->getPrivacyStatus() === 'public';
            $video->transitionTo($public ? VideoStatus::Published : VideoStatus::Scheduled, [
                'published_at' => $public ? now() : null,
                'last_error' => config('autoposter.audit_passed') ? null : 'Uploaded as private: the YouTube API audit has not passed yet.',
            ]);

            return;
        }

        if ($this->check >= self::MAX_CHECKS) {
            $video->fail('YouTube was still processing after 3 hours.');

            return;
        }

        self::dispatch($video, $this->check + 1)->delay(now()->addMinutes(2));
    }

    public function failed(Throwable $e): void
    {
        $this->video->fresh()?->fail($e);
    }
}
