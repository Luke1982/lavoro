<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantUsage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads a question and says how hard it looks, so the answer can go somewhere
 * that fits.
 *
 * Which model answers used to be decided by what tools somebody was *allowed* to
 * use — the hardest one on their list. That sorted people neatly until one hard
 * thing became available to everybody, at which point it sorted nobody: everyone
 * needed a model able to diagnose a fault, including to be told how many
 * werkbonnen were open.
 *
 * So the question decides instead. Reading it costs a fraction of a cent on a
 * cheap model; getting it wrong costs a question answered on something too dim,
 * which is why anything unclear rounds upward.
 */
class QuestionSorter
{
    private const CHEAPEST = 1;

    private const DEAREST = 10;

    /**
     * Which difficulty a question is actually answered at.
     *
     * The rating, held down to what this person's tools can reach. Both halves
     * matter and neither is much use alone: the ceiling alone puts every question
     * on the dearest model the moment one hard tool is shared, and the rating
     * alone would hand somebody a model that cannot drive the tools they have.
     *
     * Lives here rather than in the controller because the command line asks
     * questions too, and it used to route by the ceiling alone — so the one tool
     * anybody would reach for to find out why everything goes to the dear model
     * was answering about a route production does not take.
     */
    public function difficultyFor(string $question, User $user, ToolRegistry $registry): int
    {
        $ceiling = $registry->requiredDifficultyFor($user);
        $asked = $this->difficultyOf($question, $user);

        return $asked === null ? $ceiling : min($asked, $ceiling);
    }

    /**
     * How hard this question looks, on the same one-to-ten scale the tools use.
     *
     * Returns null when it cannot say, and the caller then falls back to the old
     * behaviour. Sorting is an economy, never a gate: a question must not fail
     * because the thing that prices it did.
     */
    public function difficultyOf(string $question, ?User $user = null): ?int
    {
        $provider = config('assistant.question_sorter');

        if (blank($provider) || blank(config('assistant.providers.' . $provider . '.api_key'))) {
            return null;
        }

        try {
            $reply = $this->modelFor($provider)->send(
                [new UserTurn([$question])],
                [],
                $this->instructions(),
            );
        } catch (ModelUnavailable|Throwable $e) {
            Log::warning('Kon de vraag niet inschalen, dus op de veilige kant beoordeeld', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $this->record($reply, $user);

        return $this->numberIn(implode(' ', $reply->texts));
    }

    /**
     * Writes down what the sorting itself cost.
     *
     * It is a fraction of a cent, and it happens on every single question, and it
     * was going into the accounts nowhere at all. An allowance that under-reports
     * is wrong in exactly the way one that over-reports is: the number stops
     * meaning what it says.
     */
    private function record(Contracts\ModelReply $reply, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        $cost = UsageCost::forCall($reply->billableModel(), $reply->usage);

        try {
            AssistantUsage::create([
                'user_id' => $user->id,
                'model' => $cost->model,
                'input_tokens' => $cost->input_tokens,
                'output_tokens' => $cost->output_tokens,
                'cache_write_tokens' => $cost->cache_write_tokens,
                'cache_read_tokens' => $cost->cache_read_tokens,
                'cost_micros' => $cost->cost_micros,
                'cost_usd_micros' => $cost->cost_usd_micros,
                'eur_per_usd' => $cost->eur_per_usd,
            ]);
        } catch (Throwable $e) {
            Log::error('Kon de kosten van het inschalen niet vastleggen', ['exception' => $e]);
        }
    }

    private function modelFor(string $provider): Contracts\TalksToModel
    {
        $driver = config('assistant.providers.' . $provider . '.driver');

        return $driver === OpenAiCompatibleModel::class
            ? OpenAiCompatibleModel::fromConfig($provider)
            : app($driver, ['provider' => $provider]);
    }

    /**
     * The examples are the whole instruction. Told only "rate from one to ten" a
     * model will rate almost everything a five, which sorts nothing.
     */
    private function instructions(): string
    {
        return implode("\n", [
            'Je beoordeelt hoe moeilijk een vraag is voor een AI-assistent in een',
            'servicebedrijf. Antwoord met alleen een cijfer van 1 tot 10, niets anders.',
            '',
            '1-3: iets opzoeken of opsommen. "Welke werkbonnen staan open?", "Wat is het',
            'telefoonnummer van klant Jansen?", "Zoek machine SN-123."',
            '',
            '4-6: opzoeken plus een beetje redeneren of iets vastleggen. "Wie heeft deze',
            'werkbon gesloten?", "Leg een storing vast op deze machine.", "Wanneer is dit',
            'voor het laatst nagekeken?"',
            '',
            '7-8: afwegen, plannen of iets uit meerdere bronnen combineren. "Wanneer kan',
            'deze klus en met wie?", "Wie kan deze storing het beste oplossen?", "Plan drie',
            'dagen werk in met twee man."',
            '',
            '9-10: een probleem uitzoeken of ergens advies over geven, waar techniek,',
            'geschiedenis en documentatie bij elkaar moeten komen. "Waarom lekt deze airco',
            'steeds?", "Hoe los ik deze storing op?"',
            '',
            'Twijfel je, kies dan het hogere cijfer.',
        ]);
    }

    /**
     * Takes the first number it can find, because a cheap model asked for a digit
     * will sometimes say "Moeilijkheid: 7" anyway.
     */
    private function numberIn(string $text): ?int
    {
        if (!preg_match('/\d+/', $text, $found)) {
            return null;
        }

        $rating = (int) $found[0];

        return $rating >= self::CHEAPEST && $rating <= self::DEAREST ? $rating : null;
    }
}
