<?php

namespace App\Domain\Tray\Paste;

use JsonSerializable;

/**
 * What a paste asks before it goes ahead: what is being pasted, where, and the
 * choices on offer. Usually that is one answer for all of it. When the records
 * do not all go the same way, each gets a part with choices of its own, and the
 * answer is one choice per record. Either way the answer only counts if every
 * choice in it was on offer.
 */
final class Question implements JsonSerializable
{
    /**
     * @param  array<int, array{title: string, subtitle: ?string}>  $items
     * @param  array<int, Choice>  $choices  One of these is the answer...
     * @param  array<int, QuestionPart>  $parts  ...or, instead, one of each part's choices, by its key.
     * @param  ?string  $confirm  The button's words when there are parts; otherwise the chosen choice has them.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $items = [],
        public readonly array $choices = [],
        public readonly ?string $legend = null,
        public readonly array $parts = [],
        public readonly ?string $confirm = null,
    ) {}

    /**
     * @param  string|array<int, string>  $answer
     */
    public function offers(string|array $answer): bool
    {
        if ($this->parts === []) {
            return Choice::offered($this->choices, $answer);
        }

        return is_array($answer) && collect($this->parts)->every(
            fn (QuestionPart $part) => Choice::offered($part->choices, $answer[$part->key] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
