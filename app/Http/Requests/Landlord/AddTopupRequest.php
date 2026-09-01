<?php

namespace App\Http\Requests\Landlord;

/**
 * Eenmalig bijgekocht AI-tegoed.
 */
class AddTopupRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'paid_euro' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
        ];
    }
}
