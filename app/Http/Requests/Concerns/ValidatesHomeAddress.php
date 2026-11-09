<?php

namespace App\Http\Requests\Concerns;

/** The home address on the user form, where a route can start (RoutePlanner). */
trait ValidatesHomeAddress
{
    /** @return array<string, array<int, string>> */
    protected function homeAddressRules(): array
    {
        return [
            'home_address' => ['nullable', 'string', 'max:255'],
            'home_postal_code' => ['nullable', 'string', 'max:20'],
            'home_city' => ['nullable', 'string', 'max:255'],
        ];
    }
}
