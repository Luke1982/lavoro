<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The record type an upload or a remark names, by class. The controller builds
 * that class, so only the records on the list may get that far; the pages send
 * the class with a leading backslash.
 */
class AttachableType implements ValidationRule
{
    /**
     * @param  array<int, class-string>  $allowed
     * @param  string  $what  What is being added, as the message names it ("afbeelding").
     */
    public function __construct(private readonly array $allowed, private readonly string $what) {}

    /**
     * @param  array<int, class-string>  $allowed
     * @return class-string|null
     */
    public static function resolve(mixed $type, array $allowed): ?string
    {
        $type = is_string($type) ? ltrim($type, '\\') : null;

        return in_array($type, $allowed, true) ? $type : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::resolve($value, $this->allowed) === null) {
            $fail('Aan dit soort record kun je geen ' . $this->what . ' toevoegen.');
        }
    }
}
