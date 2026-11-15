<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Enums\VideoStatus;
use App\Filament\Resources\VideoResource;
use App\Models\Video;
use App\Services\Generation\FFmpeg;
use App\Services\Generation\Pipeline;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditVideo extends EditRecord
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VideoResource::approveAction(Actions\Action::class),
            Actions\Action::make('rebuild')->label('Rebuild video')
                ->icon('heroicon-m-arrow-path')->color('gray')
                ->visible(fn (Video $record) => $record->status->canGenerate() && ! empty($record->scenes))
                ->requiresConfirmation()
                ->modalDescription('Re-voices scenes whose narration changed, fetches missing b-roll, and renders again. Save your edits first.')
                ->action(function (Video $record) {
                    Pipeline::start($record, 'voice');
                    Notification::make()->title('Rebuilding')->success()->send();
                }),
            Actions\Action::make('unapprove')->label('Back to review')
                ->icon('heroicon-m-arrow-uturn-left')->color('gray')
                ->visible(fn (Video $record) => $record->status === VideoStatus::Queued)
                ->action(fn (Video $record) => $record->transitionTo(VideoStatus::Review, ['approved_at' => null])),
            Actions\Action::make('retry')->label('Retry')
                ->icon('heroicon-m-arrow-path')->color('warning')
                ->visible(fn (Video $record) => $record->status === VideoStatus::Failed && $record->file_path && $record->approved_at)
                ->modalDescription('Queues the upload again. If the video ID is already saved, it skips the upload and only re-checks processing.')
                ->requiresConfirmation()
                ->action(fn (Video $record) => $record->transitionTo(VideoStatus::Queued, ['last_error' => null])),
            VideoResource::generateAction(Actions\Action::class),
            Actions\DeleteAction::make()
                ->visible(fn (Video $record) => ! in_array($record->status, [VideoStatus::Uploading, VideoStatus::Processing], true)),
        ];
    }

    /** Your own clip or voice recording replaces the generated one. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $disk = Storage::disk(config('autoposter.disk'));

        foreach ($data['scenes'] ?? [] as $key => $scene) {
            if (! empty($scene['custom_asset'])) {
                $mime = $disk->mimeType($scene['custom_asset']) ?: '';
                $scene['asset_path'] = $scene['custom_asset'];
                $scene['asset_type'] = str_starts_with($mime, 'video/') ? 'video' : 'photo';
                $scene['asset_source'] = 'upload';
                $scene['asset_licence'] = 'Own work';
                $scene['asset_credit'] = null;
            }
            if (! empty($scene['custom_audio'])) {
                $scene['audio_path'] = $scene['custom_audio'];
                $scene['narration_hash'] = sha1($scene['narration']);
                $scene['duration'] = FFmpeg::fromConfig()->duration($disk->path($scene['custom_audio']));
            }
            unset($scene['custom_asset'], $scene['custom_audio']);
            $data['scenes'][$key] = $scene;
        }

        if (isset($data['scenes'])) {
            $data['scenes'] = array_values($data['scenes']);
            $data['script'] = collect($data['scenes'])->pluck('narration')->implode("\n\n");
        }

        return $data;
    }
}
