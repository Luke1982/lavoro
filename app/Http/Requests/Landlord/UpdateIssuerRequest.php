<?php

namespace App\Http\Requests\Landlord;

use App\Rules\Iban;

/**
 * Our own details as they appear on the invoice.
 */
class UpdateIssuerRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'issuer' => 'required|array',
            'issuer.email' => 'nullable|email',
            'issuer.iban' => ['nullable', new Iban],
            'issuer.payment_days' => 'nullable|integer|min:0|max:120',
            'issuer.*' => 'nullable|string|max:255',
        ];
    }
}
