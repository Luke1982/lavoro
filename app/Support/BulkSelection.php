<?php

namespace App\Support;

/**
 * How many records one action may change at once, and the sentence that says so.
 *
 * Every record a bulk action changes is announced, and each announcement can set
 * off more work: a file to delete, a date to move, a line on a timeline. Past a
 * few hundred records that no longer fits in one request. One number for the
 * lists' bulk actions and for the assistant, so neither can take on more than
 * the other, and one sentence for both when it is too many.
 */
final class BulkSelection
{
    public const LIMIT = 500;

    /**
     * "Je hebt 812 storingen geselecteerd, je kunt er maximaal 500 tegelijk bijwerken. Doe het in delen."
     *
     * @param  string  $records  What was picked, in the plural: "storingen".
     * @param  string  $action  What happens to them: "bijwerken".
     * @param  string  $how  How they were picked, for whoever did not tick them by hand.
     */
    public static function tooMany(int $count, string $records, string $action, string $how = 'geselecteerd'): string
    {
        return 'Je hebt ' . $count . ' ' . $records . ' ' . $how . ', je kunt er maximaal ' . self::LIMIT
            . ' tegelijk ' . $action . '. Doe het in delen.';
    }
}
