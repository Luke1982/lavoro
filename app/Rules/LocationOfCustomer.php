<?php

namespace App\Rules;

use App\Models\Location;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** One location of this customer, or — where that is allowed — "all" of them. */
class LocationOfCustomer implements ValidationRule
{
    public function __construct(
        private readonly ?int $customer_id,
        private readonly bool $or_all = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->or_all && $value === 'all') {
            return;
        }

        if (!is_scalar($value) || !Location::whereKey($value)->where('customer_id', $this->customer_id)->exists()) {
            $fail('Deze locatie hoort niet bij de klant van deze werkbon.');
        }
    }
}
