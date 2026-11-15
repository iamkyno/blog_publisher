<?php

namespace App\Filament\Resources\YoutubeAccountResource\Pages;

use App\Filament\Resources\YoutubeAccountResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListYoutubeAccounts extends ListRecords
{
    protected static string $resource = YoutubeAccountResource::class;

    public function mount(): void
    {
        parent::mount();

        if ([$status, $message] = session('oauth_status', [null, null])) {
            Notification::make()->title($message)->status($status)->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('connect')->label('Connect YouTube channel')
                ->icon('heroicon-m-link')
                ->url(route('oauth.google.redirect')),
        ];
    }
}
