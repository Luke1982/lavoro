<?php

namespace App\Enums;

/**
 * What happens with a standard material when its product is put on a quote.
 */
enum DefaultMaterialPrompts: string
{
    /** Added along with the product, without asking. */
    case always = 'always';

    /** Asked, ticked on, with the standard quantity filled in. */
    case ask = 'ask';

    /** Asked, but left off unless the person ticks it. */
    case suggest = 'suggest';

    public function label(): string
    {
        return match ($this) {
            self::always => 'Altijd meenemen',
            self::ask => 'Vragen, standaard aan',
            self::suggest => 'Voorstellen, standaard uit',
        };
    }

    public function isAsked(): bool
    {
        return $this !== self::always;
    }

    /** @return array<int, array{id: string, name: string}> */
    public static function comboBoxArray(): array
    {
        return array_map(fn (self $prompt) => ['id' => $prompt->value, 'name' => $prompt->label()], self::cases());
    }
}
