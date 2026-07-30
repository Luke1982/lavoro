<?php

namespace App\Domain\Assistant;

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
     * @param  array<int, string>  $results
     * @return array<int, string>
     */
    public function report(string $answer, array $results, int $user_id): array
    {
        $unverified = $this->unverifiedIn($answer, $results);

        if ($unverified !== []) {
            Log::warning('De assistent noemde records die geen tool heeft opgeleverd', [
                'user_id' => $user_id,
                'references' => $unverified,
            ]);
        }

        return $unverified;
    }
}
