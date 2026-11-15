<?php

namespace App\Models;

use App\Notifications\AutoposterAlert;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class YoutubeAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id', 'channel_title', 'refresh_token', 'status', 'last_error', 'connected_at',
    ];

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function tokenCacheKey(): string
    {
        return "yt_token_{$this->id}";
    }

    /** Token revoked or expired: pause uploads and tell the operator. */
    public function markDisconnected(string $error): void
    {
        Cache::forget($this->tokenCacheKey());
        $this->update(['status' => 'disconnected', 'last_error' => $error]);

        AutoposterAlert::send(
            'disconnected',
            "YouTube channel {$this->channel_title} was disconnected ({$error}). Uploads are paused until you reconnect it in the admin panel."
        );
    }

    public static function active(): ?self
    {
        return static::query()->where('status', 'connected')->latest('connected_at')->first();
    }
}
