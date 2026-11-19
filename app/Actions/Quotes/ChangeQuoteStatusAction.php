<?php

namespace App\Actions\Quotes;

use App\Domain\Signals\Quotes\QuoteStatusChanged;
use App\Domain\Signals\Signals;
use App\Enums\QuoteStatuses;
use App\Exceptions\Refusal;
use App\Models\Quote;

/**
 * Moves a quote to another status, along the paths QuoteStatuses allows. Sending
 * a quote without a single amount on it, options included, is refused: there is
 * nothing to say yes to.
 *
 * Like projects, the status only moves from the one this request saw, so two
 * people pressing "geaccepteerd" at once leave one line on the timeline.
 */
class ChangeQuoteStatusAction
{
    private const STAMPS = ['status', 'sent_at', 'decided_at'];

    public function execute(Quote $quote, QuoteStatuses $status, ?string $reason = null): void
    {
        $previous = $quote->statusEnum();

        if ($previous === $status) {
            return;
        }

        if (!in_array($status, $previous->next(), true)) {
            throw new Refusal(
                'Een offerte die ' . mb_strtolower($previous->value) . ' is, kan niet naar '
                . mb_strtolower($status->value) . '.'
            );
        }

        if ($status === QuoteStatuses::verzonden && $quote->totals()->isEmpty()) {
            throw new Refusal('Offerte ' . $quote->number . ' heeft nog geen regels met een bedrag.');
        }

        $changes = [
            'status' => $status->value,
            ...match (true) {
                $status === QuoteStatuses::verzonden => ['sent_at' => now(), 'decided_at' => null],
                $status->isDecided() => ['decided_at' => now()],
                default => ['decided_at' => null],
            },
        ];

        $moved = Quote::whereKey($quote->getKey())
            ->where('status', $previous->value)
            ->update([...$changes, 'updated_at' => now()]);

        if ($moved === 0) {
            return;
        }

        $quote->forceFill($changes)->syncOriginalAttributes(self::STAMPS);

        Signals::dispatch(new QuoteStatusChanged($quote, $previous->value, $status->value, $reason));
    }
}
