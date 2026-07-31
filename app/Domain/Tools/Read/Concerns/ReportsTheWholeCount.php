<?php

namespace App\Domain\Tools\Read\Concerns;

use App\Domain\Tools\ToolResult;
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
    /**
     * How many there are.
     *
     * A search that came back short already knows its own size, so the count only
     * costs a query when the ceiling was actually reached.
     */
    private function howManyInAll(Builder $matching, int $shown, int $limit): int
    {
        return $shown < $limit ? $shown : $matching->count();
    }

    /**
     * The rows and the truth about them, in one move.
     *
     * @param  array<string, mixed>  $content  Everything the tool already built.
     * @param  Builder  $matching  The query as it stood before the ceiling was put on it.
     * @param  string  $plural  What these are called, in Dutch: "storingen".
     */
    private function answerWithCount(
        array $content,
        int $shown,
        Builder $matching,
        int $limit,
        string $plural,
    ): ToolResult {
        $total = $this->howManyInAll($matching, $shown, $limit);

        $content['total'] = $total;

        if ($shown < $total) {
            $content['shown'] = $shown;

            /**
             * Merged rather than assigned. Three of these tools set a note of their
             * own, and the union that used to do this keeps the left-hand key — so
             * the sentence saying "this is not all" was dropped by exactly the
             * tools that had something else to say.
             */
            $content['note'] = trim(($content['note'] ?? '') . ' '
                . 'Dit is niet alles: er zijn er ' . $total . ', hiervan zie je er ' . $shown . '. '
                . 'Zeg dat er meer zijn en noem het totaal; presenteer deze ' . $plural . ' niet als de hele '
                . 'lijst en tel er niets uit op. Filter verder als de gebruiker iets specifieks zoekt.');
        }

        return ToolResult::ok($content, $shown < $total
            ? $shown . ' van de ' . $total . ' ' . $plural . ' getoond — verfijn de zoekopdracht voor de rest.'
            : $total . ' ' . $plural . ' gevonden.');
    }
}
