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
             * The bank wants the collection date a few working days ahead.
             * Today or earlier is refused, so it is not allowed here already.
             */
            'collect_on' => ['required', 'date', 'after:today'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['integer'],
        ];
    }
}
