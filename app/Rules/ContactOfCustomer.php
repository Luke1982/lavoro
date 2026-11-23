<?php

namespace App\Rules;

use App\Models\Contact;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** One of this customer's contact persons, on "deze werkbon", "dit project" or "deze offerte". */
class ContactOfCustomer implements ValidationRule
{
    public function __construct(
        private readonly ?int $customer_id,
        private readonly string $record,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $linked = is_scalar($value) && Contact::whereKey($value)
            ->whereHas('customers', fn ($query) => $query->whereKey($this->customer_id))
            ->exists();

        if (!$linked) {
            $fail('Deze contactpersoon hoort niet bij de klant van ' . $this->record . '.');
        }
    }
}
