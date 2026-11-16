<?php

namespace App\Domain\Tray\Paste;

/**
 * What a paste did, in the sentence shown to the person, and how to take it back
 * when it can be taken back.
 */
final class PasteResult
{
    /**
     * @param  array<string, mixed>|null  $undo  Handed back as-is to undo the paste.
     * @param  array<string, mixed>  $created  Records the paste made, for the screen to show.
     */
    public function __construct(
        public readonly string $message,
        public readonly ?array $undo = null,
        public readonly array $created = [],
    ) {}
}
