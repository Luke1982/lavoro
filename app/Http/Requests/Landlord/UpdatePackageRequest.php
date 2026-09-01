<?php

namespace App\Http\Requests\Landlord;

/**
 * Prijs en plaatsen van een pakket.
 */
class UpdatePackageRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'field_seats' => 'required|integer|min:0',
            'office_seats' => 'required|integer|min:0',
            'price_cents' => 'required|integer|min:0',
            'extra_field_cents' => 'required|integer|min:0',
            'extra_office_cents' => 'required|integer|min:0',
        ];
    }
}
