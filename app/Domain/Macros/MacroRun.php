<?php

namespace App\Domain\Macros;

use App\Models\User;

/**
 * One run of a macro: who runs it, the answers given for it, and the files it put
 * on disk, so a run that fails halfway can take them away again.
 */
final class MacroRun
{
    /** @var array<int, string> */
    private array $stored = [];

    /**
     * @param  array<string, array<string, mixed>>  $answers  Step id to its answers by field.
     */
    public function __construct(
        public readonly User $user,
        private readonly array $answers = [],
    ) {}

    public function answer(string $step_id, string $field): mixed
    {
        return $this->answers[$step_id][$field] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function answers(): array
    {
        return $this->answers;
    }

    public function stored(string $path): void
    {
        $this->stored[] = $path;
    }

    /** @return array<int, string> */
    public function storedFiles(): array
    {
        return $this->stored;
    }
}
