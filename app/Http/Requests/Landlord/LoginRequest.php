<?php

namespace App\Http\Requests\Landlord;

/**
 * Inloggen op het beheerpaneel.
 */
class LoginRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required',
        ];
    }
}
