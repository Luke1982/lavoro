<?php

namespace App\Http\Requests;

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

        if (!$user || $user->isAdmin()) {
            return (bool) $user;
        }

        foreach (['asset.read', 'ticket.create'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
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
