<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AssetUpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return $user->isAdmin() || $user->hasPermission('asset.update');
    }

    public function rules(): array
    {
        return [
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('customer_id', $this->route('asset')->customer_id)),
            ],
        ];
    }
}
