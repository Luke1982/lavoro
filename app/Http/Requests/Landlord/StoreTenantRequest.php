<?php

namespace App\Http\Requests\Landlord;

use App\Models\Central\TenantProvisioningRequest;
use App\Models\Tenant;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            /** Wereldwijd uniek: het adres wijst bij het inloggen de tenant aan. */
            'email' => ['required', 'email', 'max:255', 'unique:central.user_tenant_lookups,email'],
            'package_key' => ['required', 'exists:central.packages,key'],
            'modules' => ['array'],
            'modules.*' => ['string', 'exists:central.modules,key'],
        ];
    }

    /**
     * Twee keer dezelfde naam levert twee keer dezelfde databasenaam op. De
     * provisioner weigert dat later alsnog, maar dan staat er al een aanvraag
     * in de wacht die nooit goed kan aflopen.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = (string) $this->input('name');

            if (blank($name)) {
                return;
            }

            $database = TenantProvisioner::databaseNameFor($name);

            $taken = Tenant::on('central')->get()
                ->contains(fn ($tenant) => $tenant->getInternal('db_name') === $database);

            $queued = TenantProvisioningRequest::on('central')
                ->where('action', 'create')
                ->whereIn('status', ['queued', 'running'])
                ->where('name', $name)
                ->exists();

            if ($taken || $queued) {
                $validator->errors()->add('name', 'Er is al een tenant met deze naam of hij staat in de wacht.');
            }
        });
    }
}
