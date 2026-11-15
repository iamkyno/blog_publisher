<?php

namespace App\Filament\Widgets;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Models\YoutubeAccount;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineStats extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $count = fn (VideoStatus ...$s) => Video::whereIn('status', $s)->count();
        $next = Video::whereIn('status', [VideoStatus::Queued, VideoStatus::Scheduled])
            ->where('publish_at', '>', now())->orderBy('publish_at')->first();
        $account = YoutubeAccount::latest('connected_at')->first();

        return [
            Stat::make('Waiting for review', $count(VideoStatus::Review))
                ->color('warning'),
            Stat::make('In progress', $count(VideoStatus::Scripting, VideoStatus::Rendering, VideoStatus::Uploading, VideoStatus::Processing)),
            Stat::make('Queued / scheduled', $count(VideoStatus::Queued, VideoStatus::Scheduled))
                ->description($next ? 'Next: '.$next->publish_at->format('D j M, H:i') : 'Nothing scheduled'),
            Stat::make('Failed', $count(VideoStatus::Failed))->color('danger'),
            Stat::make('Channel', $account?->channel_title ?? 'Not connected')
                ->description($account ? ucfirst($account->status) : 'Connect it under YouTube channel')
                ->color($account?->isConnected() ? 'success' : 'danger'),
            Stat::make('API audit', config('autoposter.audit_passed') ? 'Passed' : 'Pending')
                ->description(config('autoposter.audit_passed') ? 'Scheduled public posts on' : 'Uploads stay private until it passes'),
        ];
    }
}
