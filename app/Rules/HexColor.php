<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A colour as `#rrggbb`: the only form a native colour picker shows and sends
 * back. Laravel's own `hex_color` also lets `#fff` through, which the picker
 * then shows as black.
 */
class HexColor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            $fail('Kies een kleur zoals #1d4ed8.');
        }
    }
}
