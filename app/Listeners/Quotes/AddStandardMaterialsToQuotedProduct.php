<?php

namespace App\Listeners\Quotes;

use App\Domain\Quotes\QuoteLineDrafts;
use App\Domain\Signals\Quotes\QuoteProductAdded;
use App\Domain\Signals\Quotes\StandardMaterialsAdded;
use App\Domain\Signals\Signals;

/**
 * A product set to always bring certain materials brings them: they go onto the
 * quote under it, after the ones the person chose, the standard quantity for
 * every unit offered. Nobody is asked; that is what "altijd meenemen" means.
 * They can be changed or removed afterwards like any line.
 *
 * Inside the transaction that added the product, so a quote never holds the unit
 * without the materials it always comes with.
 */
class AddStandardMaterialsToQuotedProduct
{
    public function handle(QuoteProductAdded $signal): void
    {
        $materials = array_map(
            fn (array $draft) => $signal->line->addChild($draft),
            QuoteLineDrafts::standardMaterialsFor($signal->quote, $signal->product, (float) $signal->line->quantity),
        );

        if ($materials !== []) {
            Signals::dispatch(new StandardMaterialsAdded($signal->quote, $signal->line, $materials));
        }
    }
}
