<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Exceptions\QuotaExceededException;
use App\Exceptions\YouTubeAuthException;
use App\Jobs\Concerns\WorksOnVideo;
use App\Models\Video;
use App\Notifications\AutoposterAlert;
use App\Services\YouTubeClient;
use Google\Http\MediaFileUpload;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\YouTube;
use Google\Service\YouTube\Video as YouTubeVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Uploads as private with status.publishAt, so YouTube makes the video
 * public at that exact time and processing is done well before go-live.
 */
class UploadVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WorksOnVideo;

    public const QUOTA_LOCK = 'youtube_quota_blocked_until';

    public int $tries = 3;

    public int $timeout = 3600;

    public array $backoff = [60, 300, 900];

    public function __construct(public Video $video)
    {
        $this->onQueue('uploads');
    }

    public function uniqueId(): string
    {
        return (string) $this->video->id;
    }

    public function handle(YouTubeClient $youtube): void
    {
        $video = $this->video->fresh();
        if ($video->status !== VideoStatus::Uploading) {
            return; // changed in the admin panel since dispatch
        }

        try {
            $yt = $youtube->for($video->account ?? throw new YouTubeAuthException('No YouTube account linked.'));

            // A retry after a successful insert must never upload twice.
            if (! $video->youtube_video_id) {
                $this->insert($yt, $video);
            }

            $this->setThumbnail($yt, $video);
        } catch (YouTubeAuthException $e) {
            // Pause, don't fail: it uploads once the channel is reconnected.
            $video->transitionTo(VideoStatus::Queued, ['last_error' => $e->getMessage()]);

            return;
        } catch (GoogleServiceException $e) {
            try {
                YouTubeClient::throwIfQuota($e);
            } catch (QuotaExceededException $quota) {
                $this->pauseForQuota($video);

                return;
            }
            throw $e;
        }

        $video->transitionTo(VideoStatus::Processing, ['last_error' => null]);
        CheckProcessingJob::dispatch($video)->delay(now()->addMinutes(2));
    }

    private function insert(YouTube $yt, Video $video): void
    {
        $path = $this->disk()->path((string) $video->file_path);
        if (! $video->file_path || ! is_file($path)) {
            throw new RuntimeException("Video file is missing: {$video->file_path}");
        }

        $status = [
            'privacyStatus' => 'private',
            'selfDeclaredMadeForKids' => false,
            // YouTube's required AI disclosure for realistic synthetic media.
            'containsSyntheticMedia' => (bool) $video->contains_synthetic_media,
        ];
        if (config('autoposter.audit_passed')) {
            if ($video->publish_at && $video->publish_at->isFuture()) {
                $status['publishAt'] = $video->publish_at->utc()->toIso8601ZuluString();
            } else {
                $status['privacyStatus'] = 'public'; // its slot has already passed
            }
        }
        // Before the API audit passes, YouTube locks every upload to private
        // anyway, so we upload private with no schedule and you test with that.

        $meta = new YouTubeVideo([
            'snippet' => [
                'title' => $video->title,
                'description' => $video->fullDescription(),
                'tags' => $video->tags ?? [],
                'categoryId' => $video->category_id ?: config('autoposter.default_category_id'),
            ],
            'status' => $status,
        ]);

        $client = $yt->getClient();
        $client->setDefer(true);
        $chunk = config('autoposter.chunk_size');

        try {
            $request = $yt->videos->insert('snippet,status', $meta);
            $media = new MediaFileUpload($client, $request, 'video/*', null, true, $chunk);
            $media->setFileSize(filesize($path));

            $handle = fopen($path, 'rb');
            $result = false;
            while (! $result && ! feof($handle)) {
                $result = $media->nextChunk(fread($handle, $chunk));
            }
            fclose($handle);
        } finally {
            $client->setDefer(false);
        }

        if (! $result instanceof YouTubeVideo || ! $result->getId()) {
            throw new RuntimeException('Upload finished without a video ID.');
        }

        // Saved immediately so a retry skips the insert.
        $video->update(['youtube_video_id' => $result->getId()]);
    }

    /** Needs a phone-verified channel; failure here doesn't fail the upload. */
    private function setThumbnail(YouTube $yt, Video $video): void
    {
        if (! $video->thumbnail_path || ! $this->disk()->exists($video->thumbnail_path)) {
            return;
        }

        try {
            $yt->thumbnails->set($video->youtube_video_id, [
                'data' => $this->disk()->get($video->thumbnail_path),
                'mimeType' => 'image/jpeg',
                'uploadType' => 'media',
            ]);
        } catch (GoogleServiceException $e) {
            YouTubeClient::throwIfQuota($e);
            Log::warning("Thumbnail upload failed for video {$video->id}", ['error' => $e->getMessage()]);
            AutoposterAlert::send('thumbnail', "Custom thumbnail for \"{$video->title}\" was rejected ({$e->getMessage()}). Is the channel phone-verified?", $video);
        }
    }

    private function pauseForQuota(Video $video): void
    {
        $seconds = YouTubeClient::secondsUntilQuotaReset();
        Cache::put(self::QUOTA_LOCK, now()->addSeconds($seconds)->toIso8601String(), $seconds);
        $video->transitionTo(VideoStatus::Queued, ['last_error' => 'YouTube quota exceeded; retrying after the midnight Pacific reset.']);
    }

    public function failed(Throwable $e): void
    {
        $this->video->fresh()?->fail($e);
    }
}
