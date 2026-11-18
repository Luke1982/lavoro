<?php

namespace App\Domain\Tray\Paste;

use JsonSerializable;

/**
 * One way a paste can go, with the words on the button that makes it so. A
 * choice made per record leaves the button to the question it is part of.
 */
final class Choice implements JsonSerializable
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $confirm = null,
    ) {}

    /**
     * @param  array<int, Choice>  $choices
     */
    public static function offered(array $choices, mixed $answer): bool
    {
        return collect($choices)->contains(fn (Choice $choice) => $choice->value === $answer);
    }

    /**
     * @return array<string, ?string>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
