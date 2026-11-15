<?php

namespace App\Notifications;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Alerts on failure, disconnect and publish. Goes to the admin email and,
 * if configured, to a JSON webhook (for example a WhatsApp command system).
 */
class AutoposterAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        public string $message,
        public ?int $videoId = null,
    ) {}

    public static function send(string $event, string $message, ?Video $video = null): void
    {
        $alert = new self($event, $message, $video?->id);

        if ($email = config('autoposter.admin_email')) {
            try {
                NotificationFacade::route('mail', $email)->notify($alert);
            } catch (Throwable $e) {
                Log::warning('Alert email failed', ['error' => $e->getMessage()]);
            }
        }

        if ($url = config('autoposter.webhook_url')) {
            try {
                Http::timeout(10)->post($url, [
                    'event' => $event,
                    'message' => $message,
                    'video_id' => $video?->id,
                    'youtube_url' => $video?->youtubeUrl(),
                ]);
            } catch (Throwable $e) {
                Log::warning('Alert webhook failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[YouTube Auto-Poster] '.ucfirst($this->event))
            ->line($this->message)
            ->action('Open admin panel', url('/admin'));
    }
}
