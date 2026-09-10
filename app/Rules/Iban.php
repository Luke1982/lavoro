<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The check every bank does as well: country code and check digits to the back,
 * letters to digits, and the remainder of the division by 97 has to be 1.
 * Catches nearly every typo, and a direct debit file with a wrong account
 * number is otherwise only handed back by the bank days later.
 */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $value) ?? '');

        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
            $fail('Dit is geen geldig IBAN.');

            return;
        }

        $shuffled = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';

        foreach (str_split($shuffled) as $character) {
            $digits .= ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        if ($remainder !== 1) {
            $fail('Het controlegetal van dit IBAN klopt niet.');
        }
    }
}
