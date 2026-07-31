<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssetSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isAdmin() || $user->hasPermission('asset.read'));
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
