<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Alles in het beheerpaneel hangt achter dezelfde deur: ingelogd als
 * landlord. Dat een keer opschrijven scheelt het in elk verzoek herhalen, en
 * er kan er niet eentje vergeten worden.
 */
abstract class LandlordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }
}
