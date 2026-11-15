<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Notifications\AutoposterAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_account_id', 'topic', 'angle', 'script', 'scenes', 'chapters',
        'audio_path', 'captions_path', 'render_log', 'approved_at', 'file_path',
        'title', 'description', 'tags', 'category_id', 'is_short',
        'contains_synthetic_media', 'thumbnail_text', 'thumbnail_path', 'publish_at', 'status',
        'youtube_video_id', 'attempts', 'last_error', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'scenes' => 'array',
            'chapters' => 'array',
            'tags' => 'array',
            'is_short' => 'boolean',
            'contains_synthetic_media' => 'boolean',
            'approved_at' => 'datetime',
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(YoutubeAccount::class, 'youtube_account_id');
    }

    /** Directory on the media disk that holds this video's generated files. */
    public function mediaDir(): string
    {
        return "videos/{$this->id}";
    }

    public function scopeDueForUpload(Builder $query): Builder
    {
        return $query
            ->where('status', VideoStatus::Queued)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now()->addHours(config('autoposter.upload_window_hours')));
    }

    public function transitionTo(VideoStatus $status, array $extra = []): void
    {
        $this->update(['status' => $status] + $extra);
    }

    /** Approve after review: hands the video to the upload pipeline. */
    public function approve(): void
    {
        $this->transitionTo(VideoStatus::Queued, [
            'approved_at' => now(),
            'last_error' => null,
            'youtube_account_id' => $this->youtube_account_id ?? YoutubeAccount::active()?->id,
        ]);
    }

    public function fail(Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        Log::error("Video {$this->id} failed", ['error' => $message]);

        $this->transitionTo(VideoStatus::Failed, ['last_error' => Str::limit($message, 2000)]);

        AutoposterAlert::send('failed', "Video #{$this->id} \"{$this->title}\" failed: {$message}", $this);
    }

    public function youtubeUrl(): ?string
    {
        return $this->youtube_video_id ? "https://youtu.be/{$this->youtube_video_id}" : null;
    }

    /** Description with chapter timestamps and cited sources appended. */
    public function fullDescription(): string
    {
        $description = (string) $this->description;

        if ($this->chapters && ! str_contains($description, '0:00')) {
            $lines = collect($this->chapters)->map(fn ($c) => "{$c['time']} {$c['title']}");
            $description .= "\n\nChapters\n".$lines->implode("\n");
        }

        return Str::limit($description, 4900, '');
    }
}
