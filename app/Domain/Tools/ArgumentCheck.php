<?php

namespace App\Domain\Tools;

/**
 * Whether a call fits the shape the tool asked for.
 *
 * A filter that cannot be read used to be dropped, and dropping a filter is not
 * a smaller answer — it is a different question. Asked for the machines of
 * customer "Jansen" rather than customer 1911, every filter fell away and the
 * tool returned twenty-five machines belonging to six other customers, which
 * the model then attributed to Jansen. Nothing in that answer looked wrong.
 *
 * So an argument the tool cannot read is refused here instead, with the name of
 * the argument in the complaint. The model reads that, corrects itself and asks
 * again, which is the whole point of giving it an error rather than a result.
 */
final class ArgumentCheck
{
    /**
     * The complaint, in Dutch, or nothing when the call fits.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     */
    public static function against(array $schema, array $arguments): ?string
    {
        $properties = $schema['properties'] ?? [];

        /**
         * Declared and never enforced, which read as an answer rather than a
         * question: asked to research a storing without being given one, the tool
         * reported "Storing #? niet gevonden" — a lookup that never happened,
         * phrased as one that came back empty.
         */
        foreach ($schema['required'] ?? [] as $key) {
            if (($arguments[$key] ?? null) === null || $arguments[$key] === '') {
                return 'Deze tool heeft "' . $key . '" nodig en die ontbreekt.';
            }
        }

        foreach ($arguments as $key => $value) {
            /**
             * A key nothing declares is the same failure wearing a different hat:
             * "customer" instead of "customer_id" filters on nothing at all and
             * comes back as the whole table.
             */
            if (!array_key_exists($key, $properties)) {
                if (($schema['additionalProperties'] ?? true) !== false) {
                    continue;
                }

                return 'Onbekend argument "' . $key . '". Deze tool kent: '
                    . (implode(', ', array_keys($properties)) ?: 'geen argumenten') . '.';
            }

            /** Nothing given is not something given wrongly. */
            if ($value === null) {
                continue;
            }

            $complaint = self::fits($properties[$key], $value);

            if ($complaint !== null) {
                return 'Ongeldige waarde voor "' . $key . '": ' . $complaint;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private static function fits(array $property, mixed $value): ?string
    {
        return match ($property['type'] ?? null) {
            'integer' => self::whole($value) ? null : 'geef een nummer, geen tekst. Zoek het record eerst op als je het nummer nog niet hebt.',
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                ? null
                : 'geef een getal.',
            'boolean' => self::truthy($value) ? null : 'geef true of false.',
            'string' => is_scalar($value) ? null : 'geef tekst, geen lijst.',
            'array' => self::list($property, $value),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $property
     */
    private static function list(array $property, mixed $value): ?string
    {
        $of = $property['items']['type'] ?? null;

        $wanted = match ($of) {
            'integer' => 'geef een lijst met nummers, bijvoorbeeld [12, 15]. Zoek de records eerst op als je de nummers nog niet hebt.',
            'object' => 'geef een lijst met objecten.',
            default => 'geef een lijst.',
        };

        /**
         * One id where a list was asked for is not a mistake, it is one id, and
         * integerListArgument has always read it that way. Refuse only what cannot
         * be read at all — refusing what is perfectly clear costs a round trip and
         * teaches the model nothing.
         */
        if ($of === 'integer' && self::whole($value)) {
            return null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            return $wanted;
        }

        foreach ($value as $entry) {
            if (($of === 'integer' && !self::whole($entry)) || ($of === 'object' && !is_array($entry))) {
                return $wanted;
            }
        }

        return null;
    }

    /** A whole number, written as one or as the text of one — models send both. */
    private static function whole(mixed $value): bool
    {
        return is_int($value)
            || (is_float($value) && floor($value) === $value)
            || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1);
    }

    private static function truthy(mixed $value): bool
    {
        return is_bool($value)
            || (is_int($value) && in_array($value, [0, 1], true))
            || (is_string($value) && in_array(strtolower(trim($value)), ['true', 'false', '0', '1'], true));
    }
}
