<?php

namespace App\Http\Requests\Landlord;

/**
 * Een nieuwe reseller.
 */
class StoreResellerRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'email' => 'nullable|email',
            'commission_percent' => 'required|integer|min:0|max:100',
        ];
    }
}
