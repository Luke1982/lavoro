<?php

namespace App\Domain\Tools;

use App\Models\User;
use Carbon\CarbonImmutable;

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
     * Text to search for, wrapped for a LIKE and with its wildcards defanged.
     *
     * Per cent and underscore mean "anything" to LIKE, and nothing was escaping
     * them: a search for "%" became %%% and matched every row in the table, which
     * came back looking like a list of results rather than a search that meant
     * nothing. "50%" or "MSZ_25" would have quietly matched too much in the same way.
     *
     * Not a security hole — the values are bound — but a search that lies about
     * what it found is worse than one that finds nothing.
     *
     * Escaped with a backslash, which MySQL treats as the escape character by
     * default. SQLite does not, unless a query says ESCAPE explicitly, so there a
     * term containing a real per cent sign finds nothing rather than finding the
     * row that genuinely has one in its name. Both drivers end up refusing to
     * match everything, which is the half that matters; only MySQL also matches a
     * literal wildcard. Worth knowing, since the tests run on SQLite and the
     * customers run on MySQL.
     */
    public function likeArgument(string $key): ?string
    {
        $value = $this->stringArgument($key);

        return $value === null || $value === ''
            ? null
            : '%' . addcslashes($value, '%_\\') . '%';
    }

    /**
     * A date, or nothing if it is not one.
     *
     * Checking the shape is not checking the date: "2026-13-45" is four digits, a
     * dash and two pairs, and it is also not a day in any month. Left to the parser
     * that came back as a PHP error message with a character position in it, which
     * tells a model the diary is broken rather than its own date.
     */
    public function dateArgument(string $key): ?CarbonImmutable
    {
        $value = $this->stringArgument($key);

        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        /** Rolled over rather than rejected: month thirteen becomes next January. */
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Whether something was given for this key at all, whatever came of reading it.
     *
     * Asked to narrow to a list and handed nothing usable, a tool must refuse
     * rather than answer about everything: silently widening a search is how "only
     * these two mechanics" comes back as the whole company.
     */
    public function wasGiven(string $key): bool
    {
        $value = $this->arguments[$key] ?? null;

        return $value !== null && $value !== '' && $value !== [];
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
