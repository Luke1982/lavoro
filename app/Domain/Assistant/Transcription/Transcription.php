<?php

namespace App\Domain\Assistant\Transcription;

/** What was said, how long it took to say, and who listened: the last two are what it costs. */
final class Transcription
{
    public function __construct(
        public readonly string $text,
        public readonly float $seconds,
        public readonly string $model,
    ) {}
}
