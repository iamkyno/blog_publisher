<?php

namespace App\Services\Generation;

use Anthropic\Client;
use App\Models\Video;
use RuntimeException;

/**
 * Writes the script, title, description, tags and chapter markers with the
 * Claude API, returned as JSON that matches a strict schema.
 */
class ClaudeScriptWriter implements ScriptWriter
{
    public function __construct(private Client $client) {}

    public function write(Video $video): array
    {
        $message = $this->client->beta->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 16000,
            system: $this->systemPrompt(),
            messages: [['role' => 'user', 'content' => $this->userPrompt($video)]],
            outputConfig: [
                'effort' => config('services.anthropic.effort'),
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            // If the model declines for policy reasons, retry on the
            // server-defined fallback model inside the same call.
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        if ($message->stopReason === 'refusal') {
            $why = $message->stopDetails?->explanation ?? 'no explanation given';
            throw new RuntimeException("Script request was declined: {$why}");
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Script was cut off at the token limit; shorten the topic or raise maxTokens.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true, flags: JSON_THROW_ON_ERROR);

                return $this->validate($data);
            }
        }

        throw new RuntimeException('Script response had no text block.');
    }

    private function systemPrompt(): string
    {
        $niche = config('autoposter.generation.channel_niche');
        $voice = config('autoposter.generation.channel_voice');

        return <<<PROMPT
        You write narration scripts for a YouTube channel about {$niche}.
        Channel voice: {$voice}

        The channel must stay eligible for the YouTube Partner Program, which
        does not pay for generic, repetitive or template-like videos. So every
        script needs a real angle: specific numbers, named examples, dates,
        and a clear opinion or takeaway a viewer could not get from a
        one-line summary. Never pad with filler such as "in today's video" or
        "don't forget to like and subscribe". Do not invent statistics; when
        you state a fact a viewer might check, list where it comes from in
        `sources` (publication or organisation and, if you know it, the URL).
        If you are unsure a figure is current, say so in the narration.

        Split the script into scenes of 1 to 4 sentences. Each scene has:
        - narration: exactly what the voiceover says, plain spoken English,
          numbers written the way they are spoken.
        - visual_query: 2 to 5 words to search a stock footage library for
          b-roll that fits the scene (concrete nouns, no brand names, no text).
        - chapter_title: a short chapter name when this scene starts a new
          section, otherwise an empty string. The first scene always starts a
          chapter. Use 3 to 8 chapters for long-form videos, none for Shorts.

        The title is under 70 characters, specific and honest (no clickbait
        the video does not deliver). thumbnail_text is 2 to 5 words for the
        thumbnail, a complete phrase that reads at a glance and complements
        the title rather than repeating it (for example "R240 A MONTH BACK"). The description is 2 to 4 short
        paragraphs that summarise what the viewer will learn. Give 5 to 12
        tags.
        PROMPT;
    }

    private function userPrompt(Video $video): string
    {
        $length = $video->is_short
            ? 'This is a YouTube Short: 110 to 140 words of narration in total (under 60 seconds), 4 to 7 scenes, a hook in the first sentence.'
            : sprintf(
                'This is a long-form video of about %d minutes: roughly %d words of narration in total.',
                $minutes = config('autoposter.generation.long_form_target_minutes'),
                $minutes * 150,
            );

        return "Topic: {$video->topic}\nAngle: ".($video->angle ?: 'Choose the most useful, specific angle.')."\n\n{$length}";
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'thumbnail_text' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'narration' => ['type' => 'string'],
                            'visual_query' => ['type' => 'string'],
                            'chapter_title' => ['type' => 'string'],
                        ],
                        'required' => ['narration', 'visual_query', 'chapter_title'],
                        'additionalProperties' => false,
                    ],
                ],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['title', 'thumbnail_text', 'description', 'tags', 'scenes', 'sources'],
            'additionalProperties' => false,
        ];
    }

    private function validate(array $data): array
    {
        if (empty($data['scenes'])) {
            throw new RuntimeException('Script came back with no scenes.');
        }

        return $data;
    }
}
