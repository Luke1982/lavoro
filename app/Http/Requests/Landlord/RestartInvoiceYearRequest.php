<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Validation\Rule;

/**
 * The year has to be typed over. This deletes every invoice of the year, also
 * the ones a customer, the bank or e-Boekhouden already has; a yes-no question
 * is too easy to click through.
 */
class RestartInvoiceYearRequest extends LandlordRequest
{
    public function rules(): array
    {
        return ['confirm_year' => ['required', Rule::in([now()->format('Y')])]];
    }

    public function messages(): array
    {
        return ['confirm_year.in' => 'Het jaartal komt niet overeen.'];
    }
}
