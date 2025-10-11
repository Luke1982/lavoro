<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('customer.update');
    }

    public function rules(): array
    {
        return [
            'billing_customer_id' => 'nullable|exists:customers,id',
        ];
    }
}
