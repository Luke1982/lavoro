<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inloggen op het beheerpaneel.
 *
 * Erft met opzet niet van LandlordRequest. Die eist een ingelogde landlord, en
 * dat is precies wat hier nog niet zo is: het verzoek liep dan op een 403 stuk
 * en niemand kon het paneel meer in.
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
