<?php

namespace App\Http\Requests\Landlord;

/**
 * Alleen kijken of er iets veranderd is. Geen regels, wel dezelfde deur.
 */
class LandlordStatusRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
