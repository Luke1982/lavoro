<?php

namespace App\Domain\Tools\Read\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * How many there are, rather than how many fitted.
 *
 * Every search here stops at a ceiling and every one of them reported the slice
 * as the answer: "25 storingen gevonden" with three hundred and sixty-two in the
 * table, "25 werkbonnen" out of a hundred and twenty-six. Nothing about that
 * sentence suggests it is partial, so the model repeats it as a total and
 * somebody plans their week around a number that is wrong by an order of
 * magnitude.
 *
 * The count only costs a query when the ceiling was actually reached, which is
 * the uncommon case — a search that came back short already knows its own size.
 */
trait ReportsTheWholeCount
{
    private function howManyInAll(Builder $matching, int $shown, int $limit): int
    {
        return $shown < $limit ? $shown : $matching->toBase()->getCountForPagination();
    }

    /**
     * The line a person reads. It has to say when it is showing a slice, because
     * the model will otherwise say "er zijn er 25" and mean it.
     */
    private function foundLine(int $shown, int $total, string $noun): string
    {
        return $shown < $total
            ? $shown . ' van de ' . $total . ' ' . $noun . ' getoond — verfijn de zoekopdracht voor de rest.'
            : $total . ' ' . $noun . ' gevonden.';
    }

    /**
     * What the model is told about the slice, alongside the rows themselves.
     *
     * @return array<string, mixed>
     */
    private function countNote(int $shown, int $total, string $noun): array
    {
        if ($shown >= $total) {
            return ['total' => $total];
        }

        return [
            'total' => $total,
            'shown' => $shown,
            'note' => 'Dit is niet alles: er zijn er ' . $total . ', hiervan zie je er ' . $shown . '. '
                . 'Zeg dat er meer zijn en noem het totaal; presenteer deze ' . $noun . ' niet als de hele lijst '
                . 'en tel er niets uit op. Filter verder als de gebruiker iets specifieks zoekt.',
        ];
    }
}
