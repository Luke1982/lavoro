<?php

namespace App\Actions\Quotes;

use App\Domain\Quotes\QuoteGuard;
use App\Domain\Signals\Quotes\QuoteStatusChanged;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\Quote;
use App\Models\QuoteLine;
use Closure;

/**
 * Moves a quote to another status, along the paths QuoteStatuses allows. Sending
 * a quote without a single amount on it, options included, is refused: there is
 * nothing to say yes to.
 *
 * Like projects, the status only moves from the one this request saw, so two
 * people pressing "geaccepteerd" at once leave one line on the timeline.
 *
 * Leaving "geaccepteerd" undoes the acceptance: the name, signature and chosen
 * options go, and the trail keeps who signed. Once a werkbon was made from the
 * quote, it stays accepted.
 */
class ChangeQuoteStatusAction
{
    /**
     * @param  array<string, mixed>  $with  further columns that move along, such as who accepted
     * @param  (Closure(Quote, string, string): QuoteStatusChanged)|null  $announce  the fact as it should be told,
     *                                                                               when it is more than a status
     * @return bool whether it moved
     */
    public function execute(
        Quote $quote,
        QuoteStatuses $status,
        ?string $reason = null,
        array $with = [],
        ?Closure $announce = null,
    ): bool {
        $previous = $quote->statusEnum();

        if ($previous === $status) {
            return false;
        }

        if (!in_array($status, $previous->next(), true)) {
            throw new Refusal(
                'Een offerte die ' . mb_strtolower($previous->value) . ' is, kan niet naar '
                . mb_strtolower($status->value) . '.'
            );
        }

        if ($previous === QuoteStatuses::geaccepteerd && $quote->service_order_id !== null) {
            throw new Refusal('Van offerte ' . $quote->number . ' is al een werkbon gemaakt; hij blijft geaccepteerd.');
        }

        if ($status === QuoteStatuses::verzonden) {
            QuoteGuard::offersSomething($quote);
        }

        $changes = [
            'status' => $status->value,
            ...match (true) {
                $status === QuoteStatuses::verzonden => ['sent_at' => now(), 'decided_at' => null],
                $status->isDecided() => ['decided_at' => now()],
                default => ['decided_at' => null],
            },
            ...($previous === QuoteStatuses::geaccepteerd
                ? ['accepted_name' => null, 'accepted_signature' => null, 'accepted_via' => null]
                : []),
            ...$with,
        ];

        $moved = Quote::whereKey($quote->getKey())
            ->where('status', $previous->value)
            ->update([...$changes, 'updated_at' => now()]);

        if ($moved === 0) {
            return false;
        }

        if ($previous === QuoteStatuses::geaccepteerd) {
            QuoteLine::where('quote_id', $quote->id)->update(['is_chosen' => null]);
            $quote->unsetRelation('lines');
        }

        $quote->forceFill($changes)->syncOriginalAttributes(array_keys($changes));

        Signals::dispatch($announce
            ? $announce($quote, $previous->value, $status->value)
            : new QuoteStatusChanged($quote, $previous->value, $status->value, $reason));

        return true;
    }
}
