<?php

namespace App\Http\Requests\Landlord;

/**
 * Redeeming a coupon at a customer.
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
