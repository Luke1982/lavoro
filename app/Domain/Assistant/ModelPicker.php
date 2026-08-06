<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Picks the cheapest model clever enough for the work in hand.
 *
 * Every tool says how hard it is on a scale of one to ten, and every configured
 * model says how hard a problem it can handle on the same scale. A conversation
 * needs whatever its most demanding tool needs, and this then buys the least
 * expensive answer that clears that bar.
 *
 * Both numbers are judgement, not measurement. They are a starting point to be
 * corrected against docs/assistant-testvragen.md — if a model keeps picking the
 * wrong tool, its rating is too high, and that is a config change rather than a
 * code one.
 */
class ModelPicker
{
    /** What a question costs is dominated by the prompt, which is far larger than the answer. */
    private const TYPICAL_INPUT_TOKENS = 2500;

    private const TYPICAL_OUTPUT_TOKENS = 150;

    /** @var array<string, TalksToModel> */
    private array $resolved = [];

    /** @param Closure(int): TalksToModel|null $using Overrides selection, for tests. */
    public function __construct(private readonly ?Closure $using = null) {}

    /** A picker that hands back one model whatever is asked of it. */
    public static function fixed(TalksToModel $model): self
    {
        return new self(fn () => $model);
    }

    public function forDifficulty(int $difficulty, bool $needs_vision = false): TalksToModel
    {
        if ($this->using !== null) {
            return ($this->using)($difficulty);
        }

        $provider = $this->providerFor($difficulty, $needs_vision);

        return $this->resolved[$provider] ??= $this->build($provider);
    }

    /**
     * The cheapest usable provider that claims to be up to it.
     *
     * A provider with no key is skipped: it may be the perfect fit on paper, but
     * an answer nobody can obtain is not an answer. If nothing clears the bar the
     * most capable one is used anyway and the shortfall is logged — better an
     * expensive answer than none, and better still a line in the log saying the
     * ratings need revisiting.
     */
    /** Whether any usable provider can actually look at a photo. */
    public function canSee(): bool
    {
        foreach (config('assistant.providers', []) as $settings) {
            if (filled($settings['api_key'] ?? null) && ($settings['sees_images'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function providerFor(int $difficulty, bool $needs_vision = false): string
    {
        $usable = [];

        foreach (config('assistant.providers', []) as $name => $settings) {
            if (blank($settings['api_key'] ?? null)) {
                continue;
            }

            /** Entries kept for a particular job are never picked by capability. */
            if ($settings['reserved'] ?? false) {
                continue;
            }

            /**
             * A photo sent to a provider whose adapter drops attachments is worse
             * than a refusal: the model answers about an image it never saw, in
             * the same confident voice as about one it did.
             */
            if ($needs_vision && !($settings['sees_images'] ?? false)) {
                continue;
            }

            $usable[$name] = [
                'capability' => (int) ($settings['capability'] ?? 0),
                'cost' => $this->typicalCost($settings['model'] ?? ''),
            ];
        }

        if ($usable === []) {
            return config('assistant.provider');
        }

        $capable = array_filter($usable, fn (array $it) => $it['capability'] >= $difficulty);

        if ($capable === []) {
            /**
             * Ordered explicitly rather than with max(), which on arrays compares
             * element by element in key order — so it happened to sort by
             * capability only because that key comes first, and reordering the
             * array would have silently changed which model gets chosen.
             */
            uasort($usable, fn (array $a, array $b) => $b['capability'] <=> $a['capability']);
            $best = array_key_first($usable);

            Log::warning('Geen model slim genoeg voor deze vraag, duurste gekozen', [
                'difficulty' => $difficulty,
                'chosen' => $best,
            ]);

            return $best;
        }

        uasort($capable, fn (array $a, array $b) => $a['cost'] <=> $b['cost']);

        return array_key_first($capable);
    }

    /**
     * What a representative question costs on this model, used only to order
     * candidates. An unpriced model sorts last rather than first, so a missing
     * price never reads as "free" and quietly wins every comparison.
     */
    private function typicalCost(string $model): float
    {
        $rates = Pricing::forModel($model);

        if ($rates === null) {
            return PHP_FLOAT_MAX;
        }

        return ($rates['input'] * self::TYPICAL_INPUT_TOKENS
            + $rates['output'] * self::TYPICAL_OUTPUT_TOKENS) / 1_000_000;
    }

    private function build(string $provider): TalksToModel
    {
        $driver = config('assistant.providers.' . $provider . '.driver');

        if ($driver === null) {
            throw new ModelUnavailable(ModelFailure::other, 'Onbekende AI-aanbieder: ' . $provider);
        }

        if ($driver === OpenAiCompatibleModel::class) {
            return OpenAiCompatibleModel::fromConfig($provider);
        }

        return app($driver, ['provider' => $provider]);
    }
}
