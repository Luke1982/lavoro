<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ExportCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [
            /**
             * De bank wil de incassodatum een paar werkdagen vooruit hebben.
             * Vandaag of eerder wordt geweigerd, dus dat mag hier al niet.
             */
            'collect_on' => ['required', 'date', 'after:today'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['integer'],
        ];
    }
}
