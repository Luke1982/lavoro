<?php

namespace App\Domain\Tray\Paste;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * One paste: who, what came off the bakje, where it was dropped and, once the
 * person has been asked, which of the offered choices they took.
 */
final class Paste
{
    /**
     * @param  array<string, mixed>  $target
     */
    public function __construct(
        public readonly User $user,
        public readonly Collection $records,
        public readonly array $target,
        public readonly ?string $answer = null,
    ) {}
}
