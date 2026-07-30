<?php

namespace App\Enums;

/**
 * How loudly a notification asks for attention.
 *
 * Backed by an integer, which none of the other enums here are, because this one
 * is ordered on: 'hoog' only sorts above 'normaal' by accident of the alphabet,
 * and a bell that puts the urgent thing third is worse than one with no priority
 * at all. The Dutch words live in label(), so the ranking and the wording cannot
 * drift apart.
 */
enum UserNotificationPriority: int
{
    case laag = 1;
    case normaal = 2;
    case hoog = 3;

    public function label(): string
    {
        return match ($this) {
            self::laag => 'Laag',
            self::normaal => 'Normaal',
            self::hoog => 'Hoog',
        };
    }

    /**
     * A storing's own urgency, carried through to the notification about it. The
     * two vocabularies are worded the same today, and mapping them here rather
     * than assuming that keeps them free to differ later.
     */
    public static function fromTicketPriority(?string $ticket_priority): self
    {
        return match ($ticket_priority) {
            TicketPriorities::hoog->value => self::hoog,
            TicketPriorities::laag->value => self::laag,
            default => self::normaal,
        };
    }

    /** @return array<int, array<string, mixed>> */
    public static function comboBoxArray(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->label()],
            self::cases()
        );
    }
}
