<?php

namespace App\Filament\Resources\VideoResource\Pages;

use App\Enums\VideoStatus;
use App\Filament\Resources\VideoResource;
use App\Services\Generation\Pipeline;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateVideo extends CreateRecord
{
    protected static string $resource = VideoResource::class;

    protected bool $generateAfterCreate = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = VideoStatus::Draft;
        $data['category_id'] ??= config('autoposter.default_category_id');

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('createAndGenerate')->label('Create and generate')
                ->action(function () {
                    $this->generateAfterCreate = true;
                    $this->create();
                }),
            $this->getCreateFormAction()->label('Save as draft')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterCreate(): void
    {
        if ($this->generateAfterCreate) {
            Pipeline::start($this->record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
