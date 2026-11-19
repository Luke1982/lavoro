<?php

namespace App\Domain\Integrations\Sync;

use App\Domain\Integrations\Leading;

/**
 * Which fields of one record go which way, decided per field from four
 * fingerprints: ours and theirs now, and ours and theirs at the last sync.
 *
 *   - a field only they changed is taken;
 *   - a field only we changed is kept, and sent when the connection writes;
 *   - a field both changed is settled by Leading, unless both ended on the
 *     same value, which is no clash at all;
 *   - without a last sync (a record seen for the first time, or a link from
 *     before fingerprints) nothing is known about who changed what, so every
 *     field that differs is a clash.
 *
 * Pure: no models, no database. Synchronizer applies the outcome.
 */
final class Merge
{
    /**
     * @param  array<int, string>  $take  Fields to take from them.
     * @param  array<int, string>  $keep  Fields where ours stays and should go to them.
     */
    private function __construct(
        public readonly array $take,
        public readonly array $keep,
    ) {}

    /**
     * @param  array<string, string>  $ours
     * @param  array<string, string>  $theirs
     * @param  array<string, string>|null  $ours_before
     * @param  array<string, string>|null  $theirs_before
     */
    public static function decide(array $ours, array $theirs, ?array $ours_before, ?array $theirs_before, Leading $leading): self
    {
        $take = [];
        $keep = [];

        foreach (array_keys($theirs + $ours) as $field) {
            $mine = $ours[$field] ?? null;
            $other = $theirs[$field] ?? null;

            if ($mine === $other) {
                continue;
            }

            $known = $ours_before !== null && $theirs_before !== null;
            $they_moved = !$known || $other !== ($theirs_before[$field] ?? null);
            $we_moved = !$known || $mine !== ($ours_before[$field] ?? null);

            if ($they_moved && $we_moved) {
                $leading === Leading::External ? $take[] = $field : $keep[] = $field;
            } elseif ($they_moved) {
                $take[] = $field;
            } elseif ($we_moved) {
                $keep[] = $field;
            }
        }

        return new self($take, $keep);
    }

    /**
     * The baseline of our side to store after the merge. A field we keep
     * holds on to its old baseline -- or theirs, when there was none -- so the
     * next push still sees it as a change of ours and sends it.
     *
     * @param  array<string, string>  $ours_after
     * @param  array<string, string>  $theirs
     * @param  array<string, string>|null  $ours_before
     * @return array<string, string>
     */
    public function oursBaseline(array $ours_after, array $theirs, ?array $ours_before): array
    {
        $baseline = $ours_after;

        foreach ($this->keep as $field) {
            $baseline[$field] = $ours_before[$field] ?? $theirs[$field] ?? '';
        }

        return $baseline;
    }

    public function outcome(): string
    {
        return match (true) {
            $this->take !== [] && $this->keep !== [] => 'merged',
            $this->take !== [] => 'updated',
            $this->keep !== [] => 'kept_ours',
            default => 'unchanged',
        };
    }
}
