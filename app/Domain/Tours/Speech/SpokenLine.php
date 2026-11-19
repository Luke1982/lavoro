<?php

namespace App\Domain\Tours\Speech;

/**
 * One line of a rondleiding as spoken audio, with the second its last word ends
 * when the supplier says so. Whatever follows that word is not speech.
 */
final readonly class SpokenLine
{
    public function __construct(
        public string $audio,
        public ?float $words_end = null,
    ) {}
}
