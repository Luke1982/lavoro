<?php

namespace App\Domain\Assistant\Contracts;

/**
 * A file travelling with a question.
 *
 * Held as base64 rather than as a path because the adapters have no business
 * touching the filesystem, and because whatever reads it next is over a wire.
 */
final class Attachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $media_type,
        public readonly string $base64,
    ) {}
}
