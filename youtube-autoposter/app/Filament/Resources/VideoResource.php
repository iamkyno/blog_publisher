<?php

namespace App\Filament\Resources;

use App\Enums\VideoStatus;
use App\Filament\Resources\VideoResource\Pages;
use App\Models\Video;
use App\Models\YoutubeAccount;
use App\Services\Generation\Pipeline;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = Video::where('status', VideoStatus::Review)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for your review';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Topic')
                ->description('What the video is about. The angle is what makes it worth watching (and monetizable).')
                ->schema([
                    Forms\Components\TextInput::make('topic')->required()->maxLength(255)
                        ->placeholder('How SARB rate cuts hit your bond repayment'),
                    Forms\Components\Textarea::make('angle')->rows(3)
                        ->placeholder('Your own take: specific numbers, an opinion, who it helps and how.'),
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Toggle::make('is_short')->label('YouTube Short (9:16, under 60s)')->inline(false),
                        Forms\Components\DateTimePicker::make('publish_at')->label('Publish at')
                            ->seconds(false)->minDate(now())->native(false)
                            ->helperText('Times are in '.config('app.timezone').'.'),
                        Forms\Components\Select::make('youtube_account_id')->label('Channel')
                            ->options(fn () => YoutubeAccount::pluck('channel_title', 'id'))
                            ->default(fn () => YoutubeAccount::active()?->id),
                    ]),
                ]),

            Forms\Components\Section::make('Preview')
                ->visible(fn (?Video $record) => (bool) $record?->file_path)
                ->schema([
                    Forms\Components\Placeholder::make('player')->hiddenLabel()
                        ->content(fn (Video $record) => new HtmlString(sprintf(
                            '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start">'
                            .'<video controls preload="metadata" style="max-height:480px;max-width:100%%;border-radius:8px" src="%s"></video>'
                            .'%s</div>',
                            e(route('video.media', [$record, 'video'])).'?v='.$record->updated_at?->timestamp,
                            $record->thumbnail_path
                                ? '<img alt="Thumbnail" style="width:320px;border-radius:8px" src="'.e(route('video.media', [$record, 'thumbnail'])).'?v='.$record->updated_at?->timestamp.'">'
                                : '',
                        ))),
                ]),

            Forms\Components\Section::make('Upload details')
                ->visible(fn (?Video $record) => (bool) $record?->title)
                ->schema([
                    Forms\Components\TextInput::make('title')->maxLength(100)->required(),
                    Forms\Components\TextInput::make('thumbnail_text')->maxLength(60)
                        ->helperText('2 to 5 words. Changing it takes effect on the next rebuild.'),
                    Forms\Components\Textarea::make('description')->rows(8)->maxLength(4900)
                        ->helperText('Chapter timestamps are added automatically on upload. Check that every source is real.'),
                    Forms\Components\TagsInput::make('tags'),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('category_id')->label('Category')->options([
                            '27' => 'Education', '28' => 'Science & Technology', '22' => 'People & Blogs',
                            '25' => 'News & Politics', '26' => 'Howto & Style', '24' => 'Entertainment',
                        ])->default(config('autoposter.default_category_id')),
                        Forms\Components\Toggle::make('contains_synthetic_media')->inline(false)
                            ->label('Contains realistic AI-generated voice, people or scenes')
                            ->helperText("YouTube's required AI disclosure. Leave on for AI voiceovers."),
                    ]),
                ]),

            Forms\Components\Section::make('Scenes')
                ->description('Edit narration or the b-roll search, or upload your own clip or voice, then use "Regenerate" on that scene or "Rebuild video" at the top.')
                ->visible(fn (?Video $record) => ! empty($record?->scenes))
                ->collapsible()
                ->schema([
                    Forms\Components\Repeater::make('scenes')->hiddenLabel()
                        ->addable(false)->deletable(false)->reorderable(false)
                        ->itemLabel(fn (array $state): string => trim(($state['chapter_title'] ?? '') ?: mb_strimwidth($state['narration'] ?? '', 0, 60, '…')))
                        ->collapsed()
                        ->extraItemActions([
                            Forms\Components\Actions\Action::make('regenerate')
                                ->icon('heroicon-m-arrow-path')->tooltip('Save edits, re-voice and re-fetch this scene, rebuild the video')
                                ->requiresConfirmation()
                                ->action(function (array $arguments, Forms\Components\Repeater $component, Video $record) {
                                    $state = $component->getState();
                                    $index = array_search($arguments['item'], array_keys($state), true);
                                    $item = $state[$arguments['item']];
                                    Pipeline::regenerateScene($record, $index, $item['narration'] ?? null, $item['visual_query'] ?? null);
                                    Notification::make()->title('Scene '.($index + 1).' is regenerating')->success()->send();
                                }),
                        ])
                        ->schema([
                            Forms\Components\Textarea::make('narration')->rows(3)->required(),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('visual_query')->label('B-roll search'),
                                Forms\Components\TextInput::make('chapter_title')->label('Starts chapter'),
                            ]),
                            Forms\Components\Placeholder::make('asset_info')->label('Current b-roll')
                                ->content(fn (Forms\Get $get) => $get('asset_source')
                                    ? new HtmlString(($get('asset_source') === 'upload' ? 'Your upload' : '<a class="underline" target="_blank" rel="noopener" href="'.e($get('asset_source')).'">'.e($get('asset_credit') ?? $get('asset_source')).'</a>').' · '.e($get('asset_type')))
                                    : 'None yet'),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\FileUpload::make('custom_asset')->label('Use my own clip or image')
                                    ->disk(config('autoposter.disk'))->directory('uploads')
                                    ->acceptedFileTypes(['video/mp4', 'video/quicktime', 'image/jpeg', 'image/png'])
                                    ->maxSize(512000),
                                Forms\Components\FileUpload::make('custom_audio')->label('Use my own voice recording')
                                    ->disk(config('autoposter.disk'))->directory('uploads')
                                    ->acceptedFileTypes(['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a'])
                                    ->maxSize(51200),
                            ]),
                            // Generated data the form must carry through unchanged.
                            ...collect(['audio_path', 'narration_hash', 'duration', 'asset_path', 'asset_type', 'asset_source', 'asset_licence', 'asset_credit'])
                                ->map(fn ($f) => Forms\Components\Hidden::make($f))->all(),
                        ]),
                ]),

            Forms\Components\Section::make('Status')
                ->visible(fn (?Video $record) => $record !== null)
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('status_info')->label('Status')
                        ->content(fn (Video $record) => $record->status->label()),
                    Forms\Components\Placeholder::make('error_info')->label('Last error')
                        ->visible(fn (Video $record) => (bool) $record->last_error)
                        ->content(fn (Video $record) => $record->last_error),
                    Forms\Components\Placeholder::make('youtube_info')->label('YouTube')
                        ->visible(fn (Video $record) => (bool) $record->youtube_video_id)
                        ->content(fn (Video $record) => new HtmlString('<a class="underline" target="_blank" rel="noopener" href="'.e($record->youtubeUrl()).'">'.e($record->youtubeUrl()).'</a>')),
                    Forms\Components\Textarea::make('render_log')->rows(6)->disabled()->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_path')->label('')
                    ->getStateUsing(fn (Video $record) => $record->thumbnail_path ? route('video.media', [$record, 'thumbnail']) : null)
                    ->width(96)->height(54),
                Tables\Columns\TextColumn::make('title')->label('Video')
                    ->getStateUsing(fn (Video $record) => $record->title ?: $record->topic)
                    ->description(fn (Video $record) => $record->title ? $record->topic : null)
                    ->searchable(['title', 'topic'])->wrap()->limit(80),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (VideoStatus $state) => $state->label())
                    ->color(fn (VideoStatus $state) => $state->color())
                    ->tooltip(fn (Video $record) => $record->last_error),
                Tables\Columns\TextColumn::make('is_short')->label('Format')->badge()->color('gray')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Short' : 'Long'),
                Tables\Columns\TextColumn::make('publish_at')->dateTime('D j M, H:i')->sortable()->placeholder('Not scheduled'),
                Tables\Columns\TextColumn::make('youtube_video_id')->label('YouTube')
                    ->url(fn (Video $record) => $record->youtubeUrl(), true)
                    ->formatStateUsing(fn () => 'Open')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(VideoStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                self::generateAction(Tables\Actions\Action::class),
                self::approveAction(Tables\Actions\Action::class),
                Tables\Actions\EditAction::make()->label('Review'),
            ]);
    }

    /** @param class-string<Tables\Actions\Action|Action> $class */
    public static function generateAction(string $class)
    {
        return $class::make('generate')
            ->label(fn (Video $record) => $record->script ? 'Regenerate all' : 'Generate')
            ->icon('heroicon-m-sparkles')
            ->color('gray')
            ->visible(fn (Video $record) => $record->status->canGenerate() && $record->topic)
            ->requiresConfirmation(fn (Video $record) => (bool) $record->script)
            ->modalDescription('This writes a new script and replaces the voice, visuals and render.')
            ->action(function (Video $record) {
                Pipeline::start($record);
                Notification::make()->title('Generating: script → voice → visuals → captions → render → thumbnail')->success()->send();
            });
    }

    /** @param class-string<Tables\Actions\Action|Action> $class */
    public static function approveAction(string $class)
    {
        return $class::make('approve')
            ->icon('heroicon-m-check-badge')
            ->color('success')
            ->visible(fn (Video $record) => $record->status === VideoStatus::Review)
            ->requiresConfirmation()
            ->modalHeading('Approve for upload?')
            ->modalDescription('Have you watched it and checked the facts and sources? It will be uploaded within '.config('autoposter.upload_window_hours').' hours of its publish time.')
            ->action(function (Video $record) {
                if (! $record->publish_at) {
                    Notification::make()->title('Set a publish time first')->danger()->send();

                    return;
                }
                if (! ($record->account ?? YoutubeAccount::active())) {
                    Notification::make()->title('Connect a YouTube channel first')->danger()->send();

                    return;
                }
                $record->approve();
                Notification::make()->title('Approved and queued')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVideos::route('/'),
            'create' => Pages\CreateVideo::route('/create'),
            'edit' => Pages\EditVideo::route('/{record}/edit'),
        ];
    }
}
