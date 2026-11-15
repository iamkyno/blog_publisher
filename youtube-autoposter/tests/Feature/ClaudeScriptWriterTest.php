<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Models\Video;
use App\Services\Generation\ClaudeScriptWriter;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

class ClaudeScriptWriterTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $body, ?RequestInterface &$captured = null): Client
    {
        $transport = new class($body, $captured) implements ClientInterface
        {
            public function __construct(private array $body, public ?RequestInterface &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->captured = $request;

                return new Response(200, ['Content-Type' => 'application/json', 'request-id' => 'req_test'], json_encode($this->body));
            }
        };

        return new Client(apiKey: 'test-key', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);
    }

    private function message(array $content, string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
    }

    public function test_requests_structured_script_with_fallbacks(): void
    {
        $script = [
            'title' => 'What a 25bp SARB cut saves on a R1.5m bond',
            'thumbnail_text' => 'R240 A MONTH BACK',
            'description' => 'The maths.',
            'tags' => ['bond', 'repo rate'],
            'scenes' => [['narration' => 'Rates fell.', 'visual_query' => 'house keys', 'chapter_title' => 'Intro']],
            'sources' => ['SARB MPC statement'],
        ];
        $captured = null;
        $writer = new ClaudeScriptWriter($this->client($this->message([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => json_encode($script)],
        ]), $captured));

        $video = Video::factory()->create(['topic' => 'SARB rate cut', 'is_short' => true]);
        $result = $writer->write($video);

        $this->assertSame($script['title'], $result['title']);

        $sent = json_decode((string) $captured->getBody(), true);
        $this->assertStringContainsString('/v1/messages', (string) $captured->getUri());
        $this->assertSame('claude-opus-5-5', $sent['model']);
        $this->assertSame('default', $sent['fallbacks']);
        $this->assertSame('json_schema', $sent['output_config']['format']['type']);
        $this->assertSame('high', $sent['output_config']['effort']);
        $this->assertArrayNotHasKey('thinking', $sent);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $captured->getHeaderLine('anthropic-beta'));
        $this->assertStringContainsString('YouTube Short', $sent['messages'][0]['content']);
    }

    public function test_refusal_raises(): void
    {
        $writer = new ClaudeScriptWriter($this->client($this->message([], 'refusal')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');
        $writer->write(Video::factory()->create());
    }
}
