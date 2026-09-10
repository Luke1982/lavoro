<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Logging in to the admin panel.
 *
 * Deliberately does not inherit from LandlordRequest. That one demands a logged
 * in landlord, and that is exactly what is not the case yet here: the request
 * then broke on a 403 and nobody could get into the panel any more.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required',
        ];
    }
}
