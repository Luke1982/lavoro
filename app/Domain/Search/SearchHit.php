<?php

namespace App\Domain\Search;

/**
 * One jumpable result: what to put on screen and where Enter takes you.
 *
 * Meta holds the short facts worth a glance before deciding — a werkbon's stage
 * and the days it is planned on. They are separate from the subtitle because
 * they are labels rather than a sentence, and the row renders them as such.
 */
class SearchHit
{
    /** @param string[] $meta */
    public function __construct(
        public readonly string $group,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $href,
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'href' => $this->href,
            'meta' => $this->meta,
        ];
    }
}
