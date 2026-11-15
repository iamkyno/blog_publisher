<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Notifications\AutoposterAlert;
use Illuminate\Console\Command;

/**
 * YouTube flips scheduled videos public at publishAt on its own; this marks
 * them published here and sends the "it's live" alert.
 */
class MarkPublished extends Command
{
    protected $signature = 'autoposter:mark-published';

    protected $description = 'Mark scheduled videos whose publish time has passed as published';

    public function handle(): int
    {
        if (! config('autoposter.audit_passed')) {
            $this->info('Audit not passed yet: uploads stay private, nothing to mark.');

            return self::SUCCESS;
        }

        $videos = Video::query()
            ->where('status', VideoStatus::Scheduled)
            ->where('publish_at', '<=', now())
            ->get();

        foreach ($videos as $video) {
            $video->transitionTo(VideoStatus::Published, ['published_at' => $video->publish_at]);
            AutoposterAlert::send('published', "\"{$video->title}\" is live: {$video->youtubeUrl()}", $video);
        }

        $this->info("Marked {$videos->count()} video(s) published.");

        return self::SUCCESS;
    }
}
