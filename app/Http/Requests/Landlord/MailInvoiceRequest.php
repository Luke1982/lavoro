<?php

namespace App\Http\Requests\Landlord;

/**
 * Geen regels: er valt niets te valideren aan een knop. Wel een eigen klasse,
 * zodat ook deze handeling langs dezelfde deur gaat als de rest van het paneel.
 */
class MailInvoiceRequest extends LandlordRequest
{
    public function rules(): array
    {
        return [];
    }
}
