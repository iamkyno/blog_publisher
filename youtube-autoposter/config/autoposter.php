<?php

return [

    /*
    | Who gets alerts (failures, disconnects, publishes). Also the only
    | account allowed into the admin panel in production.
    */
    'admin_email' => env('AUTOPOSTER_ADMIN_EMAIL'),

    /*
    | Optional JSON webhook for alerts, e.g. your WhatsApp command system.
    | The app POSTs {"event": "...", "message": "...", "video_id": ...}.
    */
    'webhook_url' => env('AUTOPOSTER_WEBHOOK_URL'),

    /*
    | Set to true once Google approves the YouTube API audit. Until then
    | every upload is private and unscheduled (YouTube forces private).
    */
    'audit_passed' => (bool) env('YOUTUBE_AUDIT_PASSED', false),

    // Disk that holds generated media. Rendering needs a local disk.
    'disk' => env('AUTOPOSTER_DISK', 'local'),

    // Upload a queued video this many hours before its publish_at.
    'upload_window_hours' => (int) env('AUTOPOSTER_UPLOAD_WINDOW_HOURS', 24),

    // Resumable upload chunk size in bytes (must be a multiple of 256 KB).
    'chunk_size' => 8 * 1024 * 1024,

    // Default YouTube category: 27 = Education, 28 = Science & Technology.
    'default_category_id' => env('AUTOPOSTER_DEFAULT_CATEGORY', '27'),

    'generation' => [
        // "Niche drives pay": used in the script prompt.
        'channel_niche' => env('AUTOPOSTER_NICHE', 'personal finance in South Africa'),
        'channel_voice' => env('AUTOPOSTER_CHANNEL_VOICE', 'Plain-spoken, specific, a little dry. Explains like a smart friend, never hypes.'),
        'long_form_target_minutes' => 9,

        // tts driver: elevenlabs | openai
        'tts_driver' => env('AUTOPOSTER_TTS_DRIVER', 'elevenlabs'),

        // Royalty-free music bed (optional). Path on the media disk.
        'music_path' => env('AUTOPOSTER_MUSIC_PATH'),
        'music_volume' => (float) env('AUTOPOSTER_MUSIC_VOLUME', 0.08),

        // TTF/OTF font used for captions and thumbnails.
        'font_path' => env('AUTOPOSTER_FONT_PATH', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),

        'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
        'render_timeout' => (int) env('AUTOPOSTER_RENDER_TIMEOUT', 3600),
    ],
];
