<?php

namespace App\Domain\Tray\Paste;

use JsonSerializable;

/**
 * One record of a paste that is answered record by record: what it is, where it
 * stands now, and the choices for that record alone.
 */
final class QuestionPart implements JsonSerializable
{
    /**
     * @param  int  $key  The record's id, under which its answer comes back.
     * @param  array<int, Choice>  $choices
     */
    public function __construct(
        public readonly int $key,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $choices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
