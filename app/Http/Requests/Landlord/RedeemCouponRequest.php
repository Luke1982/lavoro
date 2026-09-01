<?php

namespace App\Http\Requests\Landlord;

/**
 * Een bon verzilveren bij een klant.
 */
class RedeemCouponRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'code' => 'required|string',
        ];
    }
}
