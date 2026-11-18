<?php

namespace App\Domain\Tray\Paste;

use App\Domain\Tray\Kinds\TrayKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One paste: who, what kind of thing came off the bakje and which of them, where
 * it was dropped and, once the person has been asked, which of the offered
 * choices they took: one for all of it, or one per record by its id.
 */
final class Paste
{
    /**
     * @param  class-string<TrayKind>  $kind
     * @param  array<string, mixed>  $target
     * @param  string|array<int, string>|null  $answer
     */
    public function __construct(
        public readonly User $user,
        public readonly string $kind,
        public readonly Collection $records,
        public readonly array $target,
        public readonly string|array|null $answer = null,
    ) {}

    /**
     * @param  string|array<int, string>|null  $answer
     */
    public function withAnswer(string|array|null $answer): self
    {
        return new self($this->user, $this->kind, $this->records, $this->target, $answer);
    }

    /** The choice made for this record: its own, or the one made for all of them. */
    public function answerFor(Model $record): ?string
    {
        return is_array($this->answer) ? ($this->answer[$record->getKey()] ?? null) : $this->answer;
    }

    /** "de storing", "beide storingen" or "alle 3 storingen". */
    public function every(): string
    {
        return ($this->kind)::every($this->records->count());
    }

    /** "Storing" or "3 storingen", to start a sentence with. */
    public function heading(): string
    {
        return ($this->kind)::heading($this->records->count());
    }

    /** "Deze storing staat" or "Deze storingen staan", by how many there are. */
    public function these(string $verb_one, string $verb_many): string
    {
        $kind = $this->kind;

        return $this->records->count() === 1
            ? 'Deze ' . $kind::one() . ' ' . $verb_one
            : 'Deze ' . $kind::many() . ' ' . $verb_many;
    }
}
