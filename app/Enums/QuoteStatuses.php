<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum QuoteStatuses: string
{
    use EnumComboBoxArrayTrait;

    case concept = 'Concept';
    case verzonden = 'Verzonden';
    case wijziging_gevraagd = 'Wijziging gevraagd';
    case geaccepteerd = 'Geaccepteerd';
    case afgewezen = 'Afgewezen';
    case vervallen = 'Vervallen';

    /**
     * What the customer holds is not changed under their hands: a sent quote
     * goes back to concept first, and the trail says so. A quote the customer
     * asked to change is open for exactly that, and is sent again afterwards.
     */
    public function allowsEditing(): bool
    {
        return $this === self::concept || $this === self::wijziging_gevraagd;
    }

    public function isDecided(): bool
    {
        return in_array($this, [self::geaccepteerd, self::afgewezen, self::vervallen], true);
    }

    /** Still waiting for an answer: the list opens on these. */
    public function isOpen(): bool
    {
        return !$this->isDecided();
    }

    /** @return array<int, string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOpen())
        ));
    }

    /**
     * Reached only along with what they carry: accepted with a name and a
     * signature (AcceptQuoteAction), a change asked for with a message
     * (RequestQuoteChangesAction). A plain status button never sets them.
     */
    public function isAnswer(): bool
    {
        return $this === self::geaccepteerd || $this === self::wijziging_gevraagd;
    }

    /**
     * Where a quote in this status may go next. An accepted quote only goes
     * back to concept, which undoes the acceptance; ChangeQuoteStatusAction
     * refuses even that once a werkbon was made from it.
     *
     * @return array<int, self>
     */
    public function next(): array
    {
        return match ($this) {
            self::concept => [self::verzonden, self::geaccepteerd],
            self::verzonden => [
                self::concept, self::wijziging_gevraagd, self::geaccepteerd, self::afgewezen, self::vervallen,
            ],
            self::wijziging_gevraagd => [self::verzonden, self::concept, self::geaccepteerd, self::afgewezen],
            self::geaccepteerd => [self::concept],
            self::afgewezen, self::vervallen => [self::verzonden, self::concept],
        };
    }

    /** Out with the customer, so the link they hold may still be answered. */
    public function awaitsAnswer(): bool
    {
        return $this === self::verzonden;
    }

    /** Whether a quote in this status may still be accepted. */
    public function allowsAccepting(): bool
    {
        return in_array(self::geaccepteerd, $this->next(), true);
    }

    /** @return array<int, self> where a plain status button may take it */
    public function nextByHand(): array
    {
        return array_values(array_filter($this->next(), fn (self $status) => !$status->isAnswer()));
    }
}
