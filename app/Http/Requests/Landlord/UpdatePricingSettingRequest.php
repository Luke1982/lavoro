<?php

namespace App\Http\Requests\Landlord;

/**
 * Een losse prijsinstelling.
 */
class UpdatePricingSettingRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|integer|min:0',
        ];
    }
}
