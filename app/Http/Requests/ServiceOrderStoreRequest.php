<?php

namespace App\Http\Requests;

use App\Models\Asset;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Support\AssetOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ServiceOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceOrder::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
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
                if ($validator->errors()->isEmpty() && !$this->belongsToOneCustomer()) {
                    $validator->errors()->add('assets', 'Machines en storingen op één werkbon horen bij één klant.');
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
        $asset_ids = $this->collect('assets')
            ->merge(Ticket::whereIn('id', $this->collect('tickets'))->pluck('asset_id'))
            ->filter();
        $assets = Asset::whereIn('id', $asset_ids)->get(['id', 'customer_id', 'parent_asset_id', 'location_id']);
        $ownership = AssetOwnership::resolve($assets);
        $customer_id = $this->integer('customer_id');

        return $assets->every(fn (Asset $asset) => (int) ($ownership[$asset->id]['customer_id'] ?? 0) === $customer_id);
    }
}
