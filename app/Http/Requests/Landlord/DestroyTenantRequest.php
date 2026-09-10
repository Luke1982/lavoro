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
     * The name has to be typed over literally. This throws away a database with
     * everything in it and there is no way back; a button with a yes-no
     * question is too easy to click on the wrong row.
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
