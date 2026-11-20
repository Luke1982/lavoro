<?php

namespace App\Domain\Quotes;

use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Collection;

/**
 * Alternatives: a few lines of which the customer takes exactly one, such as a
 * single-split or a multi-split unit. They share a choice_group; exactly one of
 * them counts. Fewer than two is no choice, so a group that shrinks to one line
 * becomes an ordinary line again.
 */
final class QuoteAlternatives
{
    /** @return Collection<int, QuoteLine> the lines of one group, in the order of the quote */
    public static function group(Quote $quote, int $group): Collection
    {
        return $quote->lines()->whereNull('parent_id')->where('choice_group', $group)->get();
    }

    /** The next group number on this quote. */
    public static function newGroup(Quote $quote): int
    {
        return (int) $quote->lines()->max('choice_group') + 1;
    }

    /** Makes this one the alternative that counts, and every other one of its group not. */
    public static function choose(QuoteLine $line): void
    {
        $line->quote->lines()->where('choice_group', $line->choice_group)->whereKeyNot($line->id)
            ->update(['is_chosen' => false]);
        $line->update(['is_chosen' => true]);
    }

    /** After a line left a group or was removed: one line left is no choice, none chosen means the first one counts. */
    public static function tidy(Quote $quote, int $group): void
    {
        $lines = self::group($quote, $group);

        if ($lines->count() < 2) {
            $lines->each(fn (QuoteLine $line) => $line->update(['choice_group' => null, 'is_chosen' => null]));

            return;
        }

        if (!$lines->contains(fn (QuoteLine $line) => $line->is_chosen === true)) {
            self::choose($lines->first());
        }
    }
}
