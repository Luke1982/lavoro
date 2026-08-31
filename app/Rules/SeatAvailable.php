<?php

namespace App\Rules;

use App\Models\Central\Package;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SeatAvailable implements ValidationRule
{
    public function __construct(private ?int $ignore_id = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! tenancy()->initialized || ! in_array($value, ['field', 'office'], true)) {
            return;
        }

        $tenant = tenancy()->tenant;
        $package = Package::on('central')->where('key', $tenant->package_key)->first();

        $limit = $value === 'field'
            ? (int) ($package->field_seats ?? 0) + (int) $tenant->extra_field_seats
            : (int) ($package->office_seats ?? 0) + (int) $tenant->extra_office_seats;

        /** Zachtgewiste gebruikers tellen niet mee: die kosten niets. */
        $query = User::where('seat_type', $value);

        if ($this->ignore_id) {
            $query->where('id', '!=', $this->ignore_id);
        }

        if ($query->count() >= $limit) {
            $fail("Alle {$limit} plaatsen voor deze soort gebruiker zijn bezet.");
        }
    }
}
