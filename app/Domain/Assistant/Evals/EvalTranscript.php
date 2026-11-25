<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolRegistry;

/**
 * Everything one run said and did: each turn with the tool calls behind it, and
 * after each turn what the simulated user did with it — the button it clicked
 * and how that went, the option it picked, what it typed. The checks read this;
 * a person and the judge read the markdown.
 */
final class EvalTranscript
{
    /**
     * How much of one tool result the judge and the probe's sorter are shown.
     * Long enough for a whole werkbon or agenda; what is cut says so.
     */
    public const SHOWN_CHARS = 12000;

    /**
     * @var array<int, array{kind: string, question: string, answer: string, model: string, rounds: int, cost_micros: int, cost_from_meter: bool, error: ?string, tools: array<int, array{name: string, arguments: array, failed: bool, content: mixed}>, pending: array, choices: array}>
     */
    public array $turns = [];

    /** @var array<int, array{turn: int, tool: string, arguments: array, ok: bool, summary: string, content: mixed}> */
    public array $executed = [];

    /** @var array<int, array{turn: int, kind: string, text: string, action: ?int}> */
    public array $user_moves = [];

    public ?EvalEnding $ending = null;

    public string $ending_detail = '';

    public function end(EvalEnding $ending, string $detail = ''): void
    {
        $this->ending = $ending;
        $this->ending_detail = $detail;
    }

    public function endingLine(): string
    {
        return ($this->ending?->label() ?? 'niet afgelopen') . ($this->ending_detail !== '' ? ': ' . $this->ending_detail : '');
    }

    public function move(int $turn, string $kind, string $text): void
    {
        $this->user_moves[] = ['turn' => $turn, 'kind' => $kind, 'text' => $text, 'action' => null];
    }

    /** @param  array{tool: string, arguments: array, ok: bool, summary: string, content: mixed}  $action */
    public function click(int $turn, string $button, array $action): void
    {
        $this->executed[] = ['turn' => $turn] + $action;
        $this->user_moves[] = ['turn' => $turn, 'kind' => 'bevestigd', 'text' => $button, 'action' => array_key_last($this->executed)];
    }

    /** @return array<int, array{name: string, arguments: array, failed: bool, content: mixed, turn: int}> */
    public function toolCalls(): array
    {
        $calls = [];

        foreach ($this->turns as $index => $turn) {
            foreach ($turn['tools'] as $tool) {
                $calls[] = $tool + ['turn' => $index];
            }
        }

        return $calls;
    }

    /** @return array<int, string> */
    public function toolNames(): array
    {
        return array_column($this->toolCalls(), 'name');
    }

    /** @return array<int, array{name: string, arguments: array, failed: bool, content: mixed, turn: int}> */
    public function callsOf(string $tool): array
    {
        return array_values(array_filter($this->toolCalls(), fn (array $call) => $call['name'] === $tool));
    }

    public function called(string $tool): bool
    {
        return $this->callsOf($tool) !== [];
    }

    public function firstCallOf(string $tool): ?int
    {
        $index = array_search($tool, $this->toolNames(), true);

        return $index === false ? null : $index;
    }

    /** @return array<int, array{turn: int, tool: string, arguments: array, ok: bool, summary: string, content: mixed}> */
    public function executedOf(string $tool): array
    {
        return array_values(array_filter($this->executed, fn (array $action) => $action['tool'] === $tool && $action['ok']));
    }

    /** @return array<int, array{turn: int, tool: string, arguments: array, ok: bool, summary: string, content: mixed}> */
    public function failedActions(): array
    {
        return array_values(array_filter($this->executed, fn (array $action) => !$action['ok']));
    }

    /** Everything the assistant wrote, in order. */
    public function answers(): string
    {
        return implode("\n", array_column($this->turns, 'answer'));
    }

    /** The id a carried-out action reports for what it made, under whichever key it uses. */
    public static function idIn(?array $action, array $keys): ?int
    {
        $content = is_array($action['content'] ?? null) ? $action['content'] : [];

        foreach ($keys as $key) {
            if (isset($content[$key]) && is_numeric($content[$key])) {
                return (int) $content[$key];
            }
        }

        return null;
    }

    /** Minutes between starts_at and ends_at as spoken on the wall clock; 0 when either is missing. */
    public static function minutesOf(array $values): int
    {
        $from = Clock::fromLocal($values['starts_at'] ?? null);
        $to = Clock::fromLocal($values['ends_at'] ?? null);

        return $from && $to ? (int) round($from->diffInMinutes($to)) : 0;
    }

    /** A tool that only proposes and waits for a click: the registry's word for it, not the name. */
    public static function isWrite(string $tool): bool
    {
        return app(ToolRegistry::class)->find($tool)?->requiresConfirmation() ?? false;
    }

    public function assistantCostMicros(): int
    {
        return array_sum(array_column($this->turns, 'cost_micros'));
    }

    /** Lookups made again with the same arguments later in the conversation: what carrying only words back costs. */
    public function repeatedLookups(): int
    {
        $seen = [];
        $repeated = 0;

        foreach ($this->toolCalls() as $call) {
            if (self::isWrite($call['name'])) {
                continue;
            }

            $key = $call['name'] . self::text($call['arguments']);
            $repeated += isset($seen[$key]) ? 1 : 0;
            $seen[$key] = true;
        }

        return $repeated;
    }

    /**
     * The conversation before a turn as the person at the keyboard saw it: what
     * they said, what came back, and how each click went.
     *
     * @return array<int, array{said: ?string, answer: string, clicks: array<int, string>}>
     */
    public function dialogue(int $before): array
    {
        $lines = [];

        foreach (array_slice($this->turns, 0, $before, true) as $index => $turn) {
            $lines[] = [
                'said' => $turn['kind'] === 'ask' ? $turn['question'] : null,
                'answer' => $turn['error'] !== null ? 'FOUT: ' . $turn['error'] : $turn['answer'],
                'clicks' => $this->clicksOn($index),
            ];
        }

        return $lines;
    }

    /** @return array<int, string> */
    public function clicksOn(int $turn): array
    {
        $clicks = [];

        foreach ($this->user_moves as $move) {
            if ($move['turn'] === $turn && $move['action'] !== null) {
                $action = $this->executed[$move['action']];
                $clicks[] = '«' . $move['text'] . '» → ' . ($action['ok'] ? 'gelukt: ' : 'niet gelukt: ') . $action['summary'];
            }
        }

        return $clicks;
    }

    /** What the person last did to get this turn: typed, picked, or clicked on the turn before. */
    public function saidFor(int $turn): string
    {
        if (($this->turns[$turn]['kind'] ?? 'ask') === 'ask') {
            return $this->turns[$turn]['question'] ?? '';
        }

        $clicks = $this->clicksOn($turn - 1);

        return 'Je klikte op bevestigen: ' . (end($clicks) ?: '?') . '. De assistent ging daarna vanzelf verder.';
    }

    public static function text(mixed $content): string
    {
        return is_string($content)
            ? $content
            : (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** At most SHOWN_CHARS of a text, saying how much was left off when anything was. */
    public static function clip(string $text, int $chars = self::SHOWN_CHARS): string
    {
        $length = mb_strlen($text);

        return $length <= $chars
            ? $text
            : mb_substr($text, 0, $chars) . ' … [ingekort: ' . ($length - $chars) . ' van ' . $length . ' tekens weggelaten]';
    }

    public function markdown(): string
    {
        $lines = [];

        foreach ($this->turns as $index => $turn) {
            $lines[] = '### ' . ($index + 1) . '. ' . $turn['kind'] . ' — ' . $turn['model']
                . ' (' . $turn['rounds'] . ' rondes, ' . Euro::micros($turn['cost_micros'], 4) . ($turn['cost_from_meter'] ? ' uit de meter' : '') . ')';
            $lines[] = '';
            $lines[] = '**Gebruiker:** ' . $turn['question'];
            $lines[] = '';

            foreach ($turn['tools'] as $tool) {
                $lines[] = '- `' . $tool['name'] . '` ' . self::text($tool['arguments']) . ($tool['failed'] ? ' **MISLUKT**' : '');
                $lines[] = '  → ' . self::clip(self::text($tool['content']));
            }

            $lines[] = '';
            $lines[] = '**Assistent:** ' . ($turn['error'] !== null ? '**FOUT:** ' . $turn['error'] : $turn['answer']);
            $lines[] = '';

            foreach ($turn['pending'] as $number => $pending) {
                $lines[] = '> knop ' . $number . ': ' . ($pending['preview'] ?? $pending['tool']);
            }

            foreach ($turn['choices'] as $choice) {
                $lines[] = '> keuze: ' . $choice['question'] . ' — ' . implode(' | ', array_column($choice['options'], 'label'));
            }

            foreach ($this->user_moves as $move) {
                if ($move['turn'] === $index) {
                    $lines[] = $this->moveLine($move);
                }
            }

            $lines[] = '';
        }

        $lines[] = 'Afloop: ' . $this->endingLine();

        return implode("\n", $lines);
    }

    private function moveLine(array $move): string
    {
        if ($move['action'] === null) {
            return '>> gebruiker ' . $move['kind'] . ': ' . $move['text'];
        }

        $action = $this->executed[$move['action']];

        return '>> gebruiker klikte «' . $move['text'] . '»: ' . ($action['ok'] ? '' : '**MISLUKT** ') . '`' . $action['tool'] . '` '
            . self::text($action['arguments']) . ' — ' . $action['summary'] . ' → ' . self::clip(self::text($action['content']));
    }

    public function toArray(): array
    {
        return [
            'turns' => $this->turns,
            'executed' => $this->executed,
            'user_moves' => $this->user_moves,
            'ending' => $this->ending?->value,
            'ending_detail' => $this->ending_detail,
        ];
    }
}
