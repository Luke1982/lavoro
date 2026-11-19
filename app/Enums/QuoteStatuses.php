<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum QuoteStatuses: string
{
    use EnumComboBoxArrayTrait;

    case concept = 'Concept';
    case verzonden = 'Verzonden';
    case geaccepteerd = 'Geaccepteerd';
    case afgewezen = 'Afgewezen';
    case vervallen = 'Vervallen';

    /**
     * Only a concept is worked on. What the customer holds is not changed under
     * their hands: to change a sent quote, it goes back to concept first, and the
     * trail says so.
     */
    public function allowsEditing(): bool
    {
        return $this === self::concept;
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

    /** @return array<int, self> where a quote in this status may go next */
    public function next(): array
    {
        return match ($this) {
            self::concept => [self::verzonden],
            self::verzonden => [self::concept, self::geaccepteerd, self::afgewezen, self::vervallen],
            self::geaccepteerd, self::afgewezen, self::vervallen => [self::verzonden],
        };
    }
}
