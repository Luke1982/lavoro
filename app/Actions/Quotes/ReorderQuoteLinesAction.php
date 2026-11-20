<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteLinesReordered;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Support\Facades\DB;

/**
 * Puts the lines of a quote in the order given: the lines at the top, or the
 * materials under one product. Lines from both levels at once are refused; a
 * material does not move out from under its product by being dragged.
 */
class ReorderQuoteLinesAction
{
    /** @param array<int, int> $line_ids every line of one level, in the new order */
    public function execute(Quote $quote, array $line_ids): void
    {
        QuoteGuard::editable($quote);

        $lines = $quote->lines()->whereKey($line_ids)->get()->keyBy('id');

        if ($lines->pluck('parent_id')->unique()->count() > 1) {
            throw new Refusal('Regels en de materialen onder een product wisselen niet van plaats.');
        }

        DB::transaction(function () use ($quote, $line_ids, $lines) {
            foreach (array_values($line_ids) as $index => $id) {
                QuoteLine::whereKey($id)->update(['position' => $index + 1]);
            }

            Signals::dispatch(new QuoteLinesReordered(
                $quote,
                array_map(fn (int $id) => (string) $lines->get($id)?->description, $line_ids),
            ));
        });
    }
}
