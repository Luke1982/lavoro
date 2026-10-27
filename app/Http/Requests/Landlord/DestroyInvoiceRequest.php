<?php

namespace App\Http\Requests\Landlord;

/**
 * No rules: there is nothing to validate about a button. A class of its own all
 * the same, so this action passes the same door as the rest of the panel.
 */
class DestroyInvoiceRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
