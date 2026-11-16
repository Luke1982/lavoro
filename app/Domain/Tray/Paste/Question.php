<?php

namespace App\Domain\Tray\Paste;

use JsonSerializable;

/**
 * What a paste asks before it goes ahead: what is being pasted, where, and the
 * choices on offer. The answer that comes back only counts if it is one of them.
 */
final class Question implements JsonSerializable
{
    /**
     * @param  array<int, array{title: string, subtitle: ?string}>  $items
     * @param  array<int, Choice>  $choices
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $items,
        public readonly array $choices,
        public readonly ?string $legend = null,
    ) {}

    public function offers(string $answer): bool
    {
        return collect($this->choices)->contains(fn (Choice $choice) => $choice->value === $answer);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
