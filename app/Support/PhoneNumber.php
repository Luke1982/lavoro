<?php

namespace App\Support;

/**
 * A phone number the way a dialer takes it, out of a field people type freely.
 */
final class PhoneNumber
{
    /**
     * Digits, optionally after a plus: all a dialer needs, and all /dial accepts.
     * Fifteen is the longest number there is; anything longer is two glued together.
     */
    public const DIALABLE = '/^\+?\d{8,15}$/';

    /**
     * The first number in the text that a dialer can use, or null when there is
     * none worth dialling.
     *
     * A field may hold a name in brackets before the number ("(Jan) 06 …"), two
     * numbers ("+31 6 1234 5678 / 020 …"), or a country code with the trunk zero
     * kept in brackets ("+31 (0)6 …"). Gluing all digits together would put a
     * number in the dialer that reaches nobody, so each stretch is tried on its own.
     */
    public static function dialable(?string $text): ?string
    {
        preg_match_all('/\+?[\d(][\d\s().-]*/', (string) $text, $stretches);

        foreach ($stretches[0] as $stretch) {
            $number = preg_replace(['/^(\+\d{1,3})\s*\(0\)/', '/[^\d+]/'], ['$1', ''], $stretch);

            if (preg_match(self::DIALABLE, $number)) {
                return $number;
            }
        }

        return null;
    }
}
