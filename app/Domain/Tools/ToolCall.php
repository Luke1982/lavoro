<?php

namespace App\Domain\Tools;

use App\Domain\Planning\Clock;
use App\Domain\Search\SearchTerm;
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
        public readonly ?string $conversation = null,
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
        return new self(
            $this->name,
            $arguments,
            $this->user,
            $this->external_id,
            $this->confirmation_token,
            $this->conversation,
        );
    }

    /**
     * The same call, placed in a conversation.
     *
     * A file dropped into the chat lives with the conversation, not with any one
     * call, so a tool that reads, notes or files it needs to know which
     * conversation it is running in — and gets that from here, never from an
     * argument the model could fill in with somebody else's.
     */
    public function inConversation(?string $conversation): self
    {
        return new self(
            $this->name,
            $this->arguments,
            $this->user,
            $this->external_id,
            $this->confirmation_token,
            $conversation,
        );
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

    /**
     * A yes or no, however the model wrote it.
     *
     * The schema says boolean and models send true, "true", 1 and "1" alike;
     * ArgumentCheck lets exactly those through. Read here and nowhere else, so a
     * "true" is never yes to one tool and no to the next.
     */
    public function booleanArgument(string $key): bool
    {
        return self::booleanFrom($this->arguments[$key] ?? false);
    }

    /** One value read the way booleanArgument reads it, for an authorize() that has only the arguments. */
    public static function booleanFrom(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function stringArgument(string $key): ?string
    {
        $value = $this->arguments[$key] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    /**
     * Text to search for as a LIKE pattern, or nothing when the argument is empty.
     *
     * What the escaping does and what it costs per database is SearchTerm::like's
     * business; an empty argument becoming null is this method's, so a caller can
     * hand it straight to a when() and leave the filter off.
     */
    public function likeArgument(string $key): ?string
    {
        $value = $this->stringArgument($key);

        return $value === null || $value === ''
            ? null
            : SearchTerm::like($value);
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
        return self::parseDate($this->stringArgument($key));
    }

    /**
     * The refusal for the first of these that was given but is not a date, or
     * nothing when every one given reads as one.
     *
     * A filter handed a date it cannot read must say so rather than leave the
     * filter off: "since Monday" answered with everything is a wrong answer
     * that looks like a right one.
     */
    public function invalidDateAmong(string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if ($this->wasGiven($key) && $this->dateArgument($key) === null) {
                return 'Geef ' . $key . ' als een echte datum in de vorm JJJJ-MM-DD, niet "'
                    . $this->stringArgument($key) . '".';
            }
        }

        return null;
    }

    /**
     * One JJJJ-MM-DD string read the way dateArgument reads it, for dates that
     * come in a list.
     *
     * Read back and compared rather than trusted, because the parser rolls over
     * rather than refuses: month thirteen becomes next January and 30 February
     * becomes 2 March, a real day that nobody asked for.
     */
    public static function parseDate(?string $value): ?CarbonImmutable
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * A wall-clock time somebody said, JJJJ-MM-DD UU:MM with optional seconds,
     * as the moment to store, or nothing when it is not a time that exists on
     * the local clock.
     *
     * Only the captured parts are read, never the whole string: handed to the
     * parser, a trailing "Z", "+05:00" or "+2 days" is taken at its word and
     * moves the time. The parser also rolls over rather than refuses, so
     * 2026-02-30 08:00 becomes the second of March and 02:30 on the night the
     * clocks go forward becomes 03:30. Read back and compared with what was
     * said, either is refused instead of put on a day or an hour nobody named.
     */
    public static function parseMoment(?string $value): ?CarbonImmutable
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $parts)) {
            return null;
        }

        if (isset($parts[4]) && (int) $parts[4] > 59) {
            return null;
        }

        $wall = $parts[1] . ' ' . $parts[2] . ':' . $parts[3];

        try {
            $moment = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, Clock::zone());
        } catch (\Throwable) {
            return null;
        }

        if ($moment === false || $moment->format('Y-m-d H:i') !== $wall) {
            return null;
        }

        return $moment->addSeconds((int) ($parts[4] ?? 0))->utc();
    }

    public function momentArgument(string $key): ?CarbonImmutable
    {
        return self::parseMoment($this->stringArgument($key));
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
