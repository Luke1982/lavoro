<?php

namespace App\Http\Requests;

use App\Models\Asset;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

class AssetSearchRequest extends FormRequest
{
    /**
     * Een storing hangt aan een machine, dus wie er een mag melden moet de machine
     * kunnen aanwijzen — ook zonder het machineoverzicht zelf te mogen openen.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('list', Asset::class) || $user->can('create', Ticket::class));
    }

    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:255',

            /** Om de lijst op één klant te versmallen. */
            'customer_id' => 'nullable|integer|exists:customers,id',
        ];
    }
}
