<?php

namespace App\Domain\Assistant\Providers;

use App\Domain\Assistant\Contracts\AssistantSaidTurn;
use App\Domain\Assistant\Contracts\AssistantTurn;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\Contracts\ToolResultsTurn;
use App\Domain\Assistant\Contracts\UserTurn;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One adapter for every supplier that speaks OpenAI's chat API.
 *
 * That is most of them: DeepSeek, Mistral, Qwen, Moonshot and Zhipu all publish
 * an OpenAI-compatible endpoint, so they differ only in a base URL, a key and a
 * model name. Which makes them config rather than code — see the providers block
 * in config/assistant.php.
 *
 * Three things differ from Anthropic in ways that are easy to get wrong, and all
 * three are handled here rather than leaking outwards:
 *
 *   - tool arguments arrive as a JSON *string*, not an object;
 *   - each tool result is its own message, so one results turn becomes several;
 *   - prompt_tokens already includes the cached tokens, so counting both without
 *     subtracting would bill the cached part twice.
 */
class OpenAiCompatibleModel implements TalksToModel
{
    public function __construct(
        private readonly string $base_url,
        private readonly string $api_key,
        private readonly string $model,
        private readonly int $max_tokens,
        private readonly int $timeout_seconds = 120,
    ) {}

    public static function fromConfig(string $provider): self
    {
        $settings = config('assistant.providers.' . $provider);

        if ($settings === null) {
            throw new ModelUnavailable(
                ModelFailure::other,
                'Onbekende AI-aanbieder in de configuratie: ' . $provider,
            );
        }

        return new self(
            base_url: rtrim($settings['base_url'], '/'),
            api_key: (string) $settings['api_key'],
            model: $settings['model'],
            max_tokens: (int) config('assistant.max_tokens'),
            timeout_seconds: (int) config('assistant.timeout_seconds', 120),
        );
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($turns as $turn) {
            foreach ($this->toMessages($turn) as $message) {
                $messages[] = $message;
            }
        }

        try {
            $response = Http::withToken($this->api_key)
                ->timeout($this->timeout_seconds)
                ->acceptJson()
                ->post($this->base_url . '/chat/completions', array_filter([
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => $this->max_tokens,
                    'tools' => $tools === [] ? null : array_map(fn (array $tool) => $this->toTool($tool), $tools),
                ], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new ModelUnavailable(ModelFailure::unreachable, $e->getMessage());
        }

        if ($response->failed()) {
            throw new ModelUnavailable($this->classify($response), $this->errorMessage($response));
        }

        return $this->toReply($response->json());
    }

    /**
     * Nobody agrees on how to say "you have run out of money", so this reads the
     * status first and only falls back to sniffing the wording. Getting it wrong
     * costs a slightly worse error message, not a wrong answer.
     */
    private function classify(Response $response): ModelFailure
    {
        if (in_array($response->status(), [401, 403], true)) {
            return ModelFailure::bad_credentials;
        }

        if ($response->status() === 402) {
            return ModelFailure::no_credit;
        }

        $body = mb_strtolower($response->body());

        foreach (['insufficient balance', 'insufficient_quota', 'credit balance', 'quota'] as $phrase) {
            if (str_contains($body, $phrase)) {
                return ModelFailure::no_credit;
            }
        }

        return ModelFailure::other;
    }

    private function errorMessage(Response $response): string
    {
        return $response->json('error.message')
            ?? $response->json('message')
            ?? ('HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
    }

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>, strict: bool}  $tool
     * @return array<string, mixed>
     */
    private function toTool(array $tool): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['input_schema'],
            ],
        ];
    }

    /**
     * One turn can become several messages: OpenAI wants a message per tool
     * result, each naming the call it answers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toMessages(mixed $turn): array
    {
        if ($turn instanceof UserTurn) {
            return [['role' => 'user', 'content' => implode("\n\n", $turn->texts)]];
        }

        if ($turn instanceof AssistantTurn) {
            return [$turn->raw];
        }

        if ($turn instanceof AssistantSaidTurn) {
            return [['role' => 'assistant', 'content' => $turn->text]];
        }

        if ($turn instanceof ToolResultsTurn) {
            return array_map(fn ($result) => [
                'role' => 'tool',
                'tool_call_id' => $result->call_id,
                'content' => $result->content,
            ], $turn->results);
        }

        throw new \InvalidArgumentException('Onbekend soort beurt: ' . get_debug_type($turn));
    }

    /** @param array<string, mixed> $payload */
    private function toReply(array $payload): ModelReply
    {
        $message = $payload['choices'][0]['message'] ?? [];
        $calls = [];

        foreach ($message['tool_calls'] ?? [] as $call) {
            $calls[] = new ModelToolCall(
                id: $call['id'],
                name: $call['function']['name'],

                /** Arguments come over as a JSON string rather than an object. */
                arguments: json_decode($call['function']['arguments'] ?: '{}', true) ?? [],
            );
        }

        $text = trim((string) ($message['content'] ?? ''));

        return new ModelReply(
            texts: $text === '' ? [] : [$text],
            tool_calls: $calls,
            usage: $this->toUsage($payload['usage'] ?? []),
            stop_reason: $this->toStopReason($payload['choices'][0]['finish_reason'] ?? null, $calls),
            model: $payload['model'] ?? $this->model,

            /** Replayed as-is, so whatever the supplier put on it survives. */
            raw: $message,
        );
    }

    /**
     * prompt_tokens counts the cached tokens too, so the cached part is taken
     * back out — otherwise the meter charges for it twice, once at the full rate
     * and once at the cached one.
     *
     * @param  array<string, mixed>  $usage
     */
    private function toUsage(array $usage): TokenUsage
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);

        /** OpenAI and Mistral nest it; DeepSeek reports it flat. */
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['prompt_cache_hit_tokens']
            ?? 0);

        return new TokenUsage(
            input: max(0, $prompt - $cached),
            output: (int) ($usage['completion_tokens'] ?? 0),

            /** These suppliers cache automatically and do not charge to write it. */
            cache_write: 0,
            cache_read: $cached,
        );
    }

    /** @param array<int, ModelToolCall> $calls */
    private function toStopReason(?string $finish_reason, array $calls): StopReason
    {
        return match ($finish_reason) {
            'tool_calls', 'function_call' => StopReason::wants_tools,
            'length' => StopReason::out_of_room,
            'content_filter' => StopReason::refused,
            default => $calls === [] ? StopReason::finished : StopReason::wants_tools,
        };
    }
}
