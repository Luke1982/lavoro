<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Eigen verzoek, want een verwijdering stuurt geen e-mailadres mee. Het
 * aanmaakverzoek hergebruiken laat de verwijdering stranden op "e-mailadres is
 * verplicht".
 */
class DestroySuperAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [];
    }
}
