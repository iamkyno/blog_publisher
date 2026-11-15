<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Jobs\UploadVideoJob;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchDueUploads extends Command
{
    protected $signature = 'autoposter:dispatch-uploads';

    protected $description = 'Queue uploads for approved videos due within the upload window';

    public function handle(): int
    {
        if ($until = Cache::get(UploadVideoJob::QUOTA_LOCK)) {
            $this->info("YouTube quota exhausted; waiting until {$until}.");

            return self::SUCCESS;
        }

        $count = 0;
        Video::query()
            ->dueForUpload()
            ->whereHas('account', fn ($q) => $q->where('status', 'connected'))
            ->orderBy('publish_at')
            ->each(function (Video $video) use (&$count) {
                // Claim it so the next run doesn't queue it again.
                $claimed = Video::whereKey($video->id)
                    ->where('status', VideoStatus::Queued)
                    ->update(['status' => VideoStatus::Uploading, 'attempts' => $video->attempts + 1]);

                if ($claimed) {
                    UploadVideoJob::dispatch($video->fresh());
                    $count++;
                }
            });

        $this->info("Queued {$count} upload(s).");

        return self::SUCCESS;
    }
}
