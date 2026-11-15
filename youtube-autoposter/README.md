# YouTube Auto-Poster

A Laravel 12 app that turns a topic into a finished video, stops for your review,
then uploads it to your YouTube channel and schedules it through the YouTube Data
API v3. It follows the plan in the *YouTube Auto-Poster — Architecture* doc.

```
topic ─► script (Claude) ─► voiceover (TTS) ─► b-roll (Pexels) ─► captions ─► FFmpeg render ─► thumbnail
                                                                                                   │
        published ◄─ scheduled ◄─ processing ◄─ uploading ◄─ queued ◄─ you approve ◄─ review ◄─────┘
```

`status` moves `draft → scripting → rendering → review → queued → uploading →
processing → scheduled → published`, or `failed` at any step.

## What's in here

| Piece | Where |
| --- | --- |
| Admin panel (topics, review, approve, per-scene edits, your own clip or voice) | `app/Filament` — Filament 3 at `/admin` |
| Channel connect + token refresh | `GoogleOAuthController`, `Services/YouTubeClient.php` |
| Generation chain | `Services/Generation/Pipeline.php`, `Jobs/Generation/*` |
| Script writer (structured JSON, server-side fallback on refusals) | `Services/Generation/ClaudeScriptWriter.php` |
| Rendering (1920×1080 or 1080×1920, burned-in captions, -14 LUFS) | `Services/Generation/FFmpeg.php` |
| Dispatcher (every 5 min) | `autoposter:dispatch-uploads` |
| Resumable upload in 8 MB chunks, `publishAt`, thumbnail, AI disclosure | `Jobs/UploadVideoJob.php` |
| Processing poller (every 2 min) | `Jobs/CheckProcessingJob.php` |
| Published check + alert (every 15 min) | `autoposter:mark-published` |
| Alerts by email and/or JSON webhook | `Notifications/AutoposterAlert.php` |

Safeguards from the plan's risk table:

- **Before the API audit passes**, uploads go up private and unscheduled
  (`YOUTUBE_AUDIT_PASSED=false`). Flip it to `true` when Google approves the audit.
- **No duplicate uploads**: `youtube_video_id` is saved the moment the insert
  returns; a retry skips straight to the thumbnail and processing check.
- **Quota**: on `quotaExceeded` the video goes back to `queued` and the dispatcher
  pauses until the midnight Pacific reset (09:00 SAST).
- **Revoked token** (`invalid_grant`): the channel is marked disconnected, uploads
  pause (they don't fail) and you get an alert to reconnect.
- **AI disclosure**: `containsSyntheticMedia` is sent on every upload (on by
  default; untick it per video if you record your own voice over your own footage).
- **Licensing**: every scene logs its asset's source page and licence.
- **Facts**: the script prompt asks for sources, which land in the description
  under "Sources" for you to check at review.

## Setup

Requirements: PHP 8.2+, Composer, MySQL, Redis, FFmpeg (with libass), and a TTF
font for captions and thumbnails (DejaVu Sans Bold by default).

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan make:filament-user      # use the same email as AUTOPOSTER_ADMIN_EMAIL
```

Fill in `.env`:

| Variable | What for |
| --- | --- |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | OAuth client of type *Web application*; redirect URI `https://your-app/oauth/google/callback` |
| `ANTHROPIC_API_KEY` | Script writing. Model defaults to `claude-opus-5-5` |
| `ELEVENLABS_API_KEY` + `ELEVENLABS_VOICE_ID`, or `AUTOPOSTER_TTS_DRIVER=openai` + `OPENAI_API_KEY` | Voiceover |
| `PEXELS_API_KEY` | Free-licence b-roll |
| `AUTOPOSTER_ADMIN_EMAIL` | Only this account can sign in to `/admin` and `/horizon` in production; alerts go here |
| `AUTOPOSTER_WEBHOOK_URL` | Optional: JSON alerts to your WhatsApp command system |
| `AUTOPOSTER_NICHE`, `AUTOPOSTER_CHANNEL_VOICE` | Steer the scripts |
| `AUTOPOSTER_MUSIC_PATH` | Optional royalty-free music bed (path on the media disk) |

### Google Cloud (do this on day one)

1. Create a project and enable **YouTube Data API v3**.
2. OAuth consent screen: External; add your Google account as a test user.
   Scopes: `youtube.upload` and `youtube.readonly`.
3. Create the OAuth client (Web application) with the redirect URI above.
4. Submit the **YouTube API Services audit** form. It takes weeks.
5. Move the consent screen to **In production**. Testing-mode refresh tokens die
   after 7 days, which would stop unattended posting.
6. Phone-verify the channel, or custom thumbnails are rejected.

Then open `/admin` → **YouTube channel** → **Connect YouTube channel**.

## Running it

```bash
php artisan horizon          # workers: default, uploads, render queues
php artisan schedule:work    # or the cron line in deploy/crontab
```

On a server, use `deploy/supervisor-horizon.conf` and `deploy/crontab`. Rendering is
CPU-heavy: run this on a VPS, not shared or Plesk hosting. Media lives on the
`local` disk under `storage/app/private/videos/{id}`.

## Daily use

1. **New topic**: topic, angle (your own take), Short or long-form, publish time.
   *Create and generate* starts the chain.
2. When it reaches **Review** (you get an alert), watch it, check the facts and
   sources, edit the title, description, tags or thumbnail text.
3. To fix a scene: edit its narration or b-roll search and press its
   ↻ *Regenerate* button, or upload your own clip or voice recording, save, and
   press **Rebuild video**. Only changed scenes are re-voiced.
4. **Approve**. It uploads within 24 hours of the publish time and YouTube makes
   it public at that minute.

Keep to 1–2 quality videos a day, with a distinct angle each: templated,
near-identical AI uploads are not eligible for the Partner Program.

## Tests

```bash
php artisan test
```

The suite fakes Claude, TTS, Pexels and the Google API (including the resumable
upload protocol) and runs the whole generation chain through real FFmpeg.
