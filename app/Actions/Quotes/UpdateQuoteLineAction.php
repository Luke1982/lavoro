<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteLineChanged;
use App\Domain\Signals\Signals;
use App\Models\QuoteLine;
use App\Support\PendingChanges;

/**
 * Changes a line on a concept quote and says which fields moved, before and
 * after. A save that changes nothing says nothing.
 */
class UpdateQuoteLineAction
{
    /** @param array<string, mixed> $data the validated fields */
    public function execute(QuoteLine $line, array $data): QuoteLine
    {
        QuoteGuard::editable($line->quote);

        $fields = PendingChanges::of($line->fill($data));

        if ($fields === []) {
            return $line;
        }

        $line->save();

        Signals::dispatch(new QuoteLineChanged($line->quote, $line, $fields));

        return $line;
    }
}
