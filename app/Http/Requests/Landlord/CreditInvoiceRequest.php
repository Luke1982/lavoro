<?php

namespace App\Http\Requests\Landlord;

/**
 * No rules: a credit note copies the invoice it cancels. A class of its own all
 * the same, so this action passes the same door as the rest of the panel.
 */
class CreditInvoiceRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
