<?php

namespace App\Domain\Assistant;

/**
 * What a model costs, looked up by its exact id.
 *
 * A separate step because model ids contain dots — gpt-4.1 — and dots are how
 * config paths are written. Asked for "assistant.pricing.gpt-4.1" the framework
 * looks for a key "4" inside a key "gpt", finds nothing, and reports the model as
 * unpriced. That failed in both directions at once: the picker sorts an unpriced
 * model last so it would never be chosen, and every call it did make would be
 * recorded as free and never count against anybody's allowance.
 */
final class Pricing
{
    /**
     * The rates for a prompt of ordinary length, which is what models are
     * compared on.
     *
     * @return array{
     *     input: float, output: float, cache_write: float, cache_read: float,
     *     long_prompt?: array<string, int|float>,
     * }|null
     */
    public static function forModel(string $model): ?array
    {
        $rates = config('assistant.pricing', [])[$model] ?? null;

        return is_array($rates) ? $rates : null;
    }

    /**
     * The rates one call is billed at. Some models charge more for the whole
     * call, output included, once its prompt is over a length.
     *
     * @return array{input: float, output: float, cache_write: float, cache_read: float}|null
     */
    public static function forPrompt(string $model, int $prompt_tokens): ?array
    {
        $rates = self::forModel($model);
        $long = $rates['long_prompt'] ?? null;

        if (is_array($long) && $prompt_tokens > $long['above_tokens']) {
            return $long;
        }

        return $rates;
    }
}
