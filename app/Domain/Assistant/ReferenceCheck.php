<?php

namespace App\Domain\Assistant;

use App\Models\AssistantToolCall;
use App\Models\Brand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Checks that the records an answer names were actually looked up.
 *
 * Pressed on whether the catalogue held any Mitsubishi aircos, the assistant
 * searched, found none, was told it was wrong, and produced six — "MSZ-AP25VGK",
 * "MSZ-LN25VG" and so on — against product ids 12 to 17, which are a Vent-Axia
 * fan, a Renson ventilation box, a Honeywell thermostat, a Vasco unit, a Vortice
 * fan and a Berker light switch. Model numbers invented whole and pinned to real
 * ids, with working links. Somebody choosing one would have put a ventilation
 * grille on a werkbon as an air conditioner.
 *
 * The system prompt forbids exactly this, and a prompt is not a guarantee. What is
 * checkable is cheaper: every record an answer links to has to be one the tools
 * returned during that same turn. Deliberately loose — an id counts if it appears
 * anywhere in any result — because the point is catching wholesale invention, and
 * a check that cries wolf gets switched off.
 */
class ReferenceCheck
{
    /** How far back a sitting reaches, for deciding what somebody has already been shown. */
    private const CONVERSATION_MINUTES = 60;

    private const CONVERSATION_CALLS = 40;

    /**
     * Paths the assistant is allowed to link to at all.
     *
     * Every one has to be a real page. "events" was on this list and there is no
     * such page — appointments live in the planner — so a link to one would have
     * been checked for honesty and then led nowhere.
     *
     * @var array<int, string>
     */
    public const RESOURCES = [
        'serviceorders',
        'tickets',
        'assets',
        'customers',
        'projects',
        'products',
        'locations',
    ];

    /**
     * The records an answer names that no tool ever returned.
     *
     * @param  array<int, string>  $results  What the tools gave back this turn, as text.
     * @return array<int, string>
     */
    public function unverifiedIn(string $answer, array $results): array
    {
        $resources = implode('|', self::RESOURCES);

        if (!preg_match_all('#/(' . $resources . ')/(\d+)#', $answer, $found, PREG_SET_ORDER)) {
            return [];
        }

        $haystack = implode(' ', $results);
        $unverified = [];

        foreach ($found as [$reference, $resource, $id]) {
            /**
             * Word boundaries on the number, so 12 is not found inside 128 and
             * quietly accepted on the strength of a different record entirely.
             */
            if (preg_match('/\b' . preg_quote($id, '/') . '\b/', $haystack)) {
                continue;
            }

            $unverified[$reference] = $resource . ' #' . $id;
        }

        return array_values(array_unique($unverified));
    }

    /**
     * Model numbers an answer names that came from no tool.
     *
     * The links were checked and this was not, so "probeer een Mitsubishi SZX 25
     * ZS-W" went out unchallenged — a model number invented on the spot, no link to
     * give it away, offered as a suggestion. Put on a werkbon it is a part nobody
     * can order.
     *
     * Shaped rather than exhaustive: a run of capitals or digits following a brand
     * name is what a model number looks like, and ordinary words are not. Catching
     * some of these beats catching none, and a check that fires on "Mitsubishi
     * buitendelen" would be turned off within a week.
     *
     * @param  array<int, string>  $results
     * @return array<int, string>
     */
    public function inventedModelsIn(string $answer, array $results, Collection $brands): array
    {
        if ($brands->isEmpty()) {
            return [];
        }

        $names = $brands->map(fn (string $brand) => preg_quote($brand, '#'))->implode('|');
        $haystack = mb_strtolower(implode(' ', $results));

        /**
         * The run of model-shaped tokens after a brand name, not one token: a model
         * number here reads "SRK 25 ZS-WF", three words that mean one thing. Taking
         * only the first missed exactly the invention it was meant to catch.
         */
        if (!preg_match_all('#(?:' . $names . ')\s+((?:[A-Z0-9][A-Z0-9/-]*\s*){1,4})#u', $answer, $found)) {
            return [];
        }

        $invented = [];

        foreach ($found[1] as $run) {
            $model = trim($run);

            /**
             * Something with a number in it. A capitalised Dutch word after a brand
             * is prose, and a check that fires on "Mitsubishi GEEN" gets switched
             * off inside a week.
             */
            if ($model === '' || !preg_match('/\d/', $model)) {
                continue;
            }

            if (!str_contains($haystack, mb_strtolower($model))) {
                $invented[$model] = $model;
            }
        }

        return array_values($invented);
    }

    /**
     * @param  array<int, string>  $results
     * @return array<int, string>
     */
    public function report(string $answer, array $results, int $user_id): array
    {
        $unverified = $this->unverifiedIn($answer, $results);

        /**
         * The brands are only worth fetching if the answer could name a model at
         * all. Loaded unconditionally this was a query on every question, including
         * the ones that are a sentence about somebody's diary.
         */
        /**
         * Loose on purpose: a run of capitals somewhere and a digit somewhere. A
         * model number here is "SZX 25 ZS-W", spread over words, so anything
         * demanding the digit next to the letters skips the very thing it is for —
         * which is what a tighter version of this line did, quietly, while still
         * saving the query.
         */
        $models = preg_match('/[A-Z]{2,}/u', $answer) === 1 && preg_match('/\d/', $answer) === 1
            ? $this->inventedModelsIn($answer, $results, Brand::query()->whereHas('products')->pluck('name'))
            : [];

        $unverified = array_merge($unverified, array_map(
            fn (string $model) => 'model ' . $model,
            $models,
        ));

        /**
         * A conversation is longer than a turn, and this only ever saw the turn.
         * Told the products two questions ago and asked to plan them now, the
         * model repeats a model number it genuinely read — and got reported for
         * inventing it, in a warning sitting above an answer that was correct.
         * A false alarm here is worse than none: it teaches people to scroll past
         * the one line that matters when it is real.
         *
         * Only asked when there is something to warn about, so the ordinary
         * question still costs nothing.
         */
        if ($unverified !== []) {
            $unverified = $this->stillUnverified($unverified, $user_id);
        }

        if ($unverified !== []) {
            Log::warning('De assistent noemde records die geen tool heeft opgeleverd', [
                'user_id' => $user_id,
                'references' => $unverified,
            ]);
        }

        return $unverified;
    }

    /**
     * The same references, minus the ones this person's own tools handed back
     * earlier in the sitting.
     *
     * @param  array<int, string>  $unverified
     * @return array<int, string>
     */
    private function stillUnverified(array $unverified, int $user_id): array
    {
        $earlier = AssistantToolCall::query()
            ->where('user_id', $user_id)
            ->where('created_at', '>=', now()->subMinutes(self::CONVERSATION_MINUTES))
            ->latest('id')
            ->limit(self::CONVERSATION_CALLS)
            ->pluck('result')
            ->implode("\n");

        if ($earlier === '') {
            return $unverified;
        }

        return array_values(array_filter(
            $unverified,
            /** The prefix is ours; what was named is the part after it. */
            fn (string $reference) => !str_contains(
                mb_strtolower($earlier),
                mb_strtolower(str_starts_with($reference, 'model ') ? mb_substr($reference, 6) : $reference),
            ),
        ));
    }
}
