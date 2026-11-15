<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Text to speech. Returns MP3 bytes. ElevenLabs or OpenAI TTS, chosen by
 * AUTOPOSTER_TTS_DRIVER. Recording your own voice and uploading it on the
 * review screen is still the strongest signal of human input.
 */
class VoiceGenerator
{
    public function synthesize(string $text): string
    {
        return match (config('autoposter.generation.tts_driver')) {
            'openai' => $this->openai($text),
            default => $this->elevenlabs($text),
        };
    }

    private function elevenlabs(string $text): string
    {
        $voice = config('services.elevenlabs.voice_id') ?: throw new RuntimeException('ELEVENLABS_VOICE_ID is not set.');

        $response = Http::withHeaders(['xi-api-key' => config('services.elevenlabs.api_key')])
            ->accept('audio/mpeg')
            ->timeout(300)
            ->retry(2, 2000, throw: false)
            ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voice}?output_format=mp3_44100_128", [
                'text' => $text,
                'model_id' => config('services.elevenlabs.model'),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('ElevenLabs TTS failed: '.$response->status().' '.$response->body());
        }

        return $response->body();
    }

    private function openai(string $text): string
    {
        $response = Http::withToken(config('services.openai_tts.api_key'))
            ->timeout(300)
            ->retry(2, 2000, throw: false)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => config('services.openai_tts.model'),
                'voice' => config('services.openai_tts.voice'),
                'input' => $text,
                'response_format' => 'mp3',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI TTS failed: '.$response->status().' '.$response->body());
        }

        return $response->body();
    }
}
