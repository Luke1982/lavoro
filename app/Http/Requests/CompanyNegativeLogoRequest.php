<?php

namespace App\Http\Requests;

/** Replacing a company's negative logo, by the same right as its logo. */
class CompanyNegativeLogoRequest extends CompanyLogoRequest
{
    public function rules(): array
    {
        return [
            'logo_negative' => 'required|image|max:2048',
        ];
    }
}
