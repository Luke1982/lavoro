<?php

namespace App\Http\Requests\Landlord;

/**
 * Kortingsbonnen voor een reseller.
 */
class StoreCouponRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'reseller_id' => 'required|integer|exists:central.resellers,id',
            'code' => 'nullable|string|max:40',
            'discount_percent' => 'required|integer|min:1|max:100',
            'discount_months' => 'required|integer|min:1|max:60',
            'aantal' => 'required|integer|min:1|max:50',
        ];
    }
}
