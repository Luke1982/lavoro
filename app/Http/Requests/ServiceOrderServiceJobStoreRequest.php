<?php

namespace App\Http\Requests;

use App\Models\ServiceJob;
use App\Rules\LocationOfCustomer;
use Illuminate\Foundation\Http\FormRequest;

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
            'location_id' => [
                'present',
                'nullable',
                'integer',
                new LocationOfCustomer($this->route('serviceorder')->customer_id),
            ],
        ];
    }
}
