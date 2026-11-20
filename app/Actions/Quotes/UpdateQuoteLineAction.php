<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteAlternatives;
use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteLineChanged;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\QuoteLine;
use App\Support\PendingChanges;
use Illuminate\Support\Facades\DB;

/**
 * Changes a line on a concept quote and says which fields moved, before and
 * after. A save that changes nothing says nothing.
 *
 * Of alternatives exactly one counts: ticking one unticks the others, and the
 * one that counts is changed by ticking another, not by unticking it.
 */
class UpdateQuoteLineAction
{
    /** @param array<string, mixed> $data the validated fields */
    public function execute(QuoteLine $line, array $data): QuoteLine
    {
        QuoteGuard::editable($line->quote);

        if ($line->isAlternative() && array_key_exists('is_chosen', $data) && !$data['is_chosen']) {
            throw new Refusal(
                'Van alternatieven telt er altijd één mee. Kies het alternatief dat wel mee moet tellen.'
            );
        }

        $fields = PendingChanges::of($line->fill($data));

        if ($fields === []) {
            return $line;
        }

        DB::transaction(function () use ($line, $fields) {
            $line->save();

            if ($line->isAlternative() && $line->wasChanged('is_chosen')) {
                QuoteAlternatives::choose($line);
            }

            Signals::dispatch(new QuoteLineChanged($line->quote, $line, $fields));
        });

        return $line;
    }
}
