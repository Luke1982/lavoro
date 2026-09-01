<?php

namespace App\Http\Requests\Landlord;

/**
 * Naam en prijs van een module.
 */
class UpdateModuleRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'price_cents' => 'required|integer|min:0',
        ];
    }
}
