<?php

namespace App\Domain\Tray\Paste;

/**
 * What a paste did, in the sentence shown to the person, how to take it back when
 * it can be taken back, and what the person chose to leave on the bakje.
 */
final class PasteResult
{
    /**
     * @param  array<string, mixed>|null  $undo  Handed back as-is to undo the paste.
     * @param  array<string, mixed>  $created  Records the paste made, for the screen to show.
     * @param  array<int, int>  $kept  Ids of the records the paste left alone; they stay on the bakje.
     */
    public function __construct(
        public readonly string $message,
        public readonly ?array $undo = null,
        public readonly array $created = [],
        public readonly array $kept = [],
    ) {}
}
