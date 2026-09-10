<?php

namespace App\Http\Requests\Landlord;

/**
 * Only checking whether something changed. No rules, but the same door.
 */
class LandlordStatusRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
