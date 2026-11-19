<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use App\Rules\ProjectAcceptsWork;
use App\Support\AssetOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServiceOrderStoreRequest extends FormRequest
{
    private ?Collection $machines = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceOrder::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'project_id' => ['nullable', 'exists:projects,id', new ProjectAcceptsWork],
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where('customer_id', $this->input('customer_id')),
            ],
            'assets' => ['sometimes', 'array'],
            'assets.*' => ['integer', 'exists:assets,id'],
            'tickets' => ['sometimes', 'array'],
            'tickets.*' => ['integer', 'exists:tickets,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (!$this->belongsToOneCustomer()) {
                    $validator->errors()->add('assets', 'Machines en storingen op één werkbon horen bij één klant.');
                } elseif (!$this->standsInOnePlace()) {
                    $validator->errors()->add('assets', 'Machines en storingen op één werkbon staan op één adres:'
                        . ' één locatie van de klant, of zijn bezoekadres. Maak per adres een werkbon.');
                }
            },
        ];
    }

    /**
     * A werkbon is for one customer, so every machine and storing put on it must be theirs.
     * A part counts as its owner's, found up its parent chain.
     */
    private function belongsToOneCustomer(): bool
    {
        $customer_id = $this->integer('customer_id');

        return $this->machines()->every(fn (?array $owner) => (int) ($owner['customer_id'] ?? 0) === $customer_id);
    }

    /**
     * And for one place, or the monteur is sent to the wrong address: a part stands where
     * its owner stands, and the customer's main address counts as a place of its own.
     */
    private function standsInOnePlace(): bool
    {
        return $this->machines()->pluck('location_id')->unique()->count() <= 1;
    }

    /** @return Collection<int, array{customer_id: int, location_id: int|null}|null> */
    private function machines(): Collection
    {
        return $this->machines ??= AssetOwnership::forWork($this->collect('assets'), $this->collect('tickets'));
    }
}
