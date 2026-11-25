<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Assistant\UsageCost;
use RuntimeException;

/**
 * Asks one of the eval's own models — the simulated user or the judge — a single
 * question and reads a JSON object out of the answer. Goes through the same
 * adapter as the assistant, so it is billed and priced the same way.
 */
final class EvalModel
{
    public int $cost_micros = 0;

    public function __construct(private readonly string $provider) {}

    /** @return array<string, mixed> */
    public function json(string $system, string $prompt): array
    {
        $model = app(ModelPicker::class)->forProvider($this->provider);
        $last = '';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reply = $model->send([new UserTurn([$prompt])], [], $system);
            $this->cost_micros += UsageCost::forCall($reply->billableModel(), $reply->usage)->cost_micros;
            $last = implode("\n", $reply->texts);
            $decoded = self::decode($last);

            if ($decoded !== null) {
                return $decoded;
            }

            $prompt .= "\n\nJe vorige antwoord was geen geldig JSON-object. Antwoord met alleen het JSON-object.";
        }

        throw new RuntimeException('Eval-model ' . $this->provider . ' gaf geen JSON: ' . mb_substr($last, 0, 300));
    }

    private static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
