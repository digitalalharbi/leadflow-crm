<?php

namespace App\Domain\Agents\Brain;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude, through the Messages API with tool use.
 *
 * The system prompt and the client's rules are marked for prompt caching: they
 * are the same on every turn of a conversation and every run of a routine, and
 * they are most of the tokens. The key is never logged and never stored in a
 * message.
 */
final class ClaudeBrain implements AgentBrain
{
    public function __construct(private string $apiKey) {}

    public function status(): array
    {
        return ['state' => 'ready', 'reason' => null];
    }

    public function think(string $agent, array $system, array $messages, array $tools): BrainReply
    {
        $config = config('services.anthropic');
        $model = $config['models'][$agent] ?? $config['models']['analyst'];

        $body = [
            'model' => $model,
            'max_tokens' => $config['max_tokens'],
            'system' => array_map(fn (array $s) => array_filter([
                'type' => 'text',
                'text' => $s['text'],
                'cache_control' => ($s['cache'] ?? false) ? ['type' => 'ephemeral'] : null,
            ]), $system),
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $config['version'],
        ])->acceptJson()->timeout($config['timeout'])->post(rtrim($config['base_url'], '/').'/v1/messages', $body);

        if ($response->failed()) {
            throw new RuntimeException('Claude API error '.$response->status().': '.$response->json('error.message', 'request failed'));
        }

        $content = $response->json('content') ?? [];
        $text = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
        $calls = collect($content)->where('type', 'tool_use')->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'input' => $c['input'] ?? []])->values()->all();

        return new BrainReply($text, $calls, $content, (string) $response->json('stop_reason'), $response->json('usage') ?? [], $model);
    }
}
