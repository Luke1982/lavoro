<?php

namespace App\Http\Requests\Landlord;

/**
 * Een mislukte aanvraag uit de lijst halen.
 *
 * Geen regels: er valt niets te valideren aan een knop. Een eigen klasse en
 * niet die van het aanmaken, want die eist een naam en een e-mailadres die hier
 * niet meegestuurd worden -- dan klaagt een wisknop over een leeg veld.
 */
class DestroyProvisioningRequestRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
