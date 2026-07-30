<?php

namespace App\Domain\Assistant\Contracts;

final class UserTurn implements Turn
{
    /**
     * @param  array<int, string>  $texts
     * @param  array<int, Attachment>  $attachments  Files the question is about.
     */
    public function __construct(
        public readonly array $texts,
        public readonly array $attachments = [],
    ) {}
}
