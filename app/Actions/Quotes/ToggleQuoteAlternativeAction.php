<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteAlternatives;
use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteAlternativeChanged;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Facades\DB;

/**
 * Makes a line an alternative to the line above it, so the customer takes one
 * of them: the line above keeps counting until the customer picks this one. A
 * line that already is an alternative stops being one, and counts by itself.
 * Three or more alternatives are made one at a time, each to the one above.
 *
 * Only priced lines at the top choose: the materials under a product go with
 * it, and a text offers nothing. An option that becomes an alternative stops
 * being an option: it is either this or that, not this as well.
 */
class ToggleQuoteAlternativeAction
{
    public function execute(QuoteLine $line): QuoteLine
    {
        $quote = $line->quote;
        QuoteGuard::editable($quote);

        DB::transaction(fn () => $line->isAlternative() ? $this->leave($quote, $line) : $this->join($quote, $line));

        return $line;
    }

    private function join(Quote $quote, QuoteLine $line): void
    {
        $above = self::lineAbove($quote, $line);

        if ($line->parent_id !== null || !$line->kindEnum()->isPriced() || !$above?->kindEnum()->isPriced()) {
            throw new Refusal(
                'Alleen een regel met een prijs kan een alternatief zijn, '
                . 'en alleen voor de regel met een prijs erboven.'
            );
        }

        if (!$above->isAlternative()) {
            $above->update([
                'choice_group' => QuoteAlternatives::newGroup($quote),
                'is_optional' => false,
                'is_chosen' => true,
            ]);
        }

        $line->update(['choice_group' => $above->choice_group, 'is_optional' => false, 'is_chosen' => false]);

        Signals::dispatch(new QuoteAlternativeChanged($quote, $line, $above));
    }

    /** The line at the top just before this one, in the order the quote shows them: by position, then by id. */
    private static function lineAbove(Quote $quote, QuoteLine $line): ?QuoteLine
    {
        return $quote->lines()
            ->whereNull('parent_id')
            ->where(fn ($query) => $query->where('position', '<', $line->position)
                ->orWhere(fn ($same) => $same->where('position', $line->position)->where('id', '<', $line->id)))
            ->reorder('position', 'desc')
            ->orderByDesc('id')
            ->first();
    }

    private function leave(Quote $quote, QuoteLine $line): void
    {
        $group = $line->choice_group;
        $line->update(['choice_group' => null, 'is_chosen' => null]);
        QuoteAlternatives::tidy($quote, $group);

        Signals::dispatch(new QuoteAlternativeChanged($quote, $line, null));
    }
}
