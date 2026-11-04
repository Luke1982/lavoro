<?php

namespace App\Http\Requests\Landlord;

/**
 * No rules: the year is always this one. A class of its own all the same, so
 * this action passes the same door as the rest of the panel.
 */
class ResetInvoiceNumbersRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
