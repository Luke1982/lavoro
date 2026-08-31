<?php

namespace App\Http\Requests\Landlord;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class DestroyTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string'],
        ];
    }

    /**
     * De naam moet letterlijk overgetikt zijn. Dit gooit een database met alles
     * erin weg en er is geen weg terug; een knop met een ja-nee-vraag is te
     * makkelijk aan te klikken op de verkeerde regel.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tenant = Tenant::on('central')->find($this->route('tenant'));

            if ($tenant && trim((string) $this->input('confirm_name')) !== $tenant->name) {
                $validator->errors()->add('confirm_name', 'De naam komt niet overeen.');
            }
        });
    }
}
