<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\TokenUsage;

/**
 * Turns the token counts from one call into what it cost.
 *
 * Everything is done in millionths of a euro and kept as integers. Money that
 * has to be summed over tens of thousands of rows and then compared against a
 * limit cannot be carried in floats, and a call costs about a hundredth of a
 * euro, so cents are far too coarse to hold one.
 *
 * The four token counts are priced separately on purpose. Cached input is
 * cheaper to read and dearer to write than ordinary input, and input_tokens
 * counts only the part of the prompt that was not cached — so adding them
 * together first would price most of the prompt wrongly.
 */
final class UsageCost
{
    private const MICROS_PER_EURO = 1_000_000;

    public function __construct(
        public readonly string $model,
        public readonly int $input_tokens,
        public readonly int $output_tokens,
        public readonly int $cache_write_tokens,
        public readonly int $cache_read_tokens,
        public readonly int $cost_usd_micros,
        public readonly int $cost_micros,
        public readonly float $eur_per_usd,
    ) {}

    public static function forCall(string $model, TokenUsage $usage): self
    {
        $rates = config('assistant.pricing.' . $model);
        $eur_per_usd = (float) config('assistant.eur_per_usd');

        $input = $usage->input;
        $output = $usage->output;
        $cache_write = $usage->cache_write;
        $cache_read = $usage->cache_read;

        /**
         * An unpriced model is recorded at zero rather than guessed at. A wrong
         * number that looks right is worse than a nought that shows up the
         * moment anyone looks at the totals.
         */
        $usd_micros = $rates === null ? 0 : (int) round(
            ($input * $rates['input']
                + $output * $rates['output']
                + $cache_write * $rates['cache_write']
                + $cache_read * $rates['cache_read'])
            * self::MICROS_PER_EURO / 1_000_000
        );

        return new self(
            model: $model,
            input_tokens: $input,
            output_tokens: $output,
            cache_write_tokens: $cache_write,
            cache_read_tokens: $cache_read,
            cost_usd_micros: $usd_micros,
            cost_micros: (int) round($usd_micros * $eur_per_usd),
            eur_per_usd: $eur_per_usd,
        );
    }

    public function euros(): float
    {
        return $this->cost_micros / self::MICROS_PER_EURO;
    }

    public function isPriced(): bool
    {
        return config('assistant.pricing.' . $this->model) !== null;
    }
}
