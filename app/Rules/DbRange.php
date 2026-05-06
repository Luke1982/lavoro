<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a numeric value fits within the range supported by a
 * particular database column type, so we never reach Eloquent with a value
 * MySQL will reject as "Numeric value out of range" (SQLSTATE 22003 / err 1264).
 *
 * Use the static factories — they encode the standard MySQL ranges:
 *   - DbRange::tinyInt() / DbRange::tinyInt(unsigned: true)
 *   - DbRange::smallInt() / DbRange::smallInt(unsigned: true)
 *   - DbRange::int()      / DbRange::int(unsigned: true)
 *   - DbRange::bigInt()   / DbRange::bigInt(unsigned: true)
 *   - DbRange::decimal($totalDigits, $decimalDigits)
 *
 * Apply alongside the standard `numeric` / `integer` rules:
 *   'price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
 *   'count' => ['integer', DbRange::int()],
 */
class DbRange implements ValidationRule
{
    private function __construct(
        private readonly float|int $min,
        private readonly float|int $max,
        private readonly string $message,
    ) {
    }

    public static function tinyInt(bool $unsigned = false): self
    {
        return $unsigned
            ? new self(0, 255, 'mag maximaal 255 zijn')
            : new self(-128, 127, 'moet tussen -128 en 127 liggen');
    }

    public static function smallInt(bool $unsigned = false): self
    {
        return $unsigned
            ? new self(0, 65535, 'mag maximaal 65.535 zijn')
            : new self(-32768, 32767, 'moet tussen -32.768 en 32.767 liggen');
    }

    public static function int(bool $unsigned = false): self
    {
        return $unsigned
            ? new self(0, 4294967295, 'mag maximaal 4.294.967.295 zijn')
            : new self(-2147483648, 2147483647, 'moet tussen -2.147.483.648 en 2.147.483.647 liggen');
    }

    public static function bigInt(bool $unsigned = false): self
    {
        return $unsigned
            ? new self(0, PHP_INT_MAX, 'is te groot')
            : new self(PHP_INT_MIN, PHP_INT_MAX, 'is te groot');
    }

    public static function decimal(int $totalDigits, int $decimalDigits): self
    {
        $intDigits = $totalDigits - $decimalDigits;
        $maxStr    = ($intDigits > 0 ? str_repeat('9', $intDigits) : '0')
            . ($decimalDigits > 0 ? '.' . str_repeat('9', $decimalDigits) : '');
        $max = (float) $maxStr;

        $message = $decimalDigits > 0
            ? sprintf(
                'mag maximaal %d cijfers voor de komma en %d cijfers na de komma hebben',
                $intDigits,
                $decimalDigits,
            )
            : sprintf('mag maximaal %d cijfers hebben', $intDigits);

        return new self(-$max, $max, $message);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_numeric($value)) {
            // Let the regular `numeric` / `integer` rules surface the type error.
            return;
        }

        $num = (float) $value;
        if ($num < $this->min || $num > $this->max) {
            $fail("De waarde van :attribute {$this->message}.");
        }
    }
}
