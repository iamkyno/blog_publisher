<?php

namespace App\Filament\Resources;

use App\Filament\Resources\YoutubeAccountResource\Pages;
use App\Models\YoutubeAccount;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class YoutubeAccountResource extends Resource
{
    protected static ?string $model = YoutubeAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationLabel = 'YouTube channel';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('No channel connected')
            ->emptyStateDescription('Connect your YouTube channel once; the app stays signed in after that.')
            ->columns([
                Tables\Columns\TextColumn::make('channel_title')->label('Channel')
                    ->url(fn (YoutubeAccount $record) => "https://www.youtube.com/channel/{$record->channel_id}", true),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'connected' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('connected_at')->since(),
                Tables\Columns\TextColumn::make('last_error')->placeholder('—')->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('reconnect')
                    ->icon('heroicon-m-arrow-path')
                    ->url(fn () => route('oauth.google.redirect')),
                Tables\Actions\DeleteAction::make()->label('Forget'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListYoutubeAccounts::route('/')];
    }
}
