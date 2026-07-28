<?php

namespace App\Domain\Tools;

use App\Models\User;

/**
 * One invocation of a tool: what was asked for, by whom, and on whose behalf.
 *
 * The user travels with the call rather than being read from Auth, so a tool is
 * testable without a session and a queued assistant turn still runs as the
 * person who started it.
 */
final class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly User $user,
        public readonly ?string $external_id = null,
        public readonly ?string $confirmation_token = null,
    ) {}

    public function argument(string $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }

    public function integerArgument(string $key): ?int
    {
        $value = $this->arguments[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function stringArgument(string $key): ?string
    {
        $value = $this->arguments[$key] ?? null;

        return $value === null ? null : trim((string) $value);
    }
}
