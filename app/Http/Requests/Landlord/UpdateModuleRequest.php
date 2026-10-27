<?php

namespace App\Http\Requests\Landlord;

/**
 * A module's name and price.
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
