<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\ServiceJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Keuringen for many machines of the werkbon's customer at once: all of them, or
 * those at one of the customer's locations.
 */
class ServiceOrderServiceJobStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', ServiceJob::class);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['present', 'nullable', 'integer'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $location_id = $this->input('location_id');
                $customer_id = $this->route('serviceorder')->customer_id;

                if ($location_id && !Location::whereKey($location_id)->where('customer_id', $customer_id)->exists()) {
                    $validator->errors()->add('location_id', 'Deze locatie hoort niet bij de klant van deze werkbon.');
                }
            },
        ];
    }
}
