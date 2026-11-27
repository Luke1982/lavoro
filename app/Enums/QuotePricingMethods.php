<?php

namespace App\Enums;

/**
 * How the price of a quote holds: fixed, or worked out afterwards from the real
 * hours and materials. The installation terms require an offer to say which.
 */
enum QuotePricingMethods: string
{
    case vaste_prijs = 'Vaste prijs';
    case nacalculatie = 'Nacalculatie';

    /**
     * Each way, as the quote page offers them, with what the customer reads.
     *
     * @return array<int, array{value: string, label: string, explanation: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => [
            'value' => $method->value,
            'label' => $method->value,
            'explanation' => $method->explanation(),
        ], self::cases());
    }

    /** The sentence the customer reads about it, on the page and on paper. */
    public function explanation(): string
    {
        return match ($this) {
            self::vaste_prijs => 'De genoemde prijs is een vaste prijs.',
            self::nacalculatie => 'Wij rekenen af op basis van de werkelijk bestede uren en gebruikte materialen (nacalculatie). '
                . 'De genoemde bedragen zijn een zo goed mogelijke inschatting.',
        };
    }
}
