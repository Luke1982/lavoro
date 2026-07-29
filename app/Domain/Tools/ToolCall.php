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

    /**
     * The same call with the arguments replaced.
     *
     * Used when an approval is redeemed: what runs comes out of the token, not
     * off the call, so the two cannot disagree.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self($this->name, $arguments, $this->user, $this->external_id, $this->confirmation_token);
    }

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

    /**
     * A list of ids, whether it arrived as a list or as a single value.
     *
     * Models are inconsistent about this even when the schema says array, and a
     * bare 42 where a list was asked for should narrow the search rather than
     * being read as a list of digits and matching nothing.
     *
     * @return array<int, int>
     */
    public function integerListArgument(string $key): array
    {
        $value = $this->arguments[$key] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            fn (int $id) => $id > 0,
        )));
    }
}
