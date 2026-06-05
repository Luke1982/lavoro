<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('customer.create'));
    }

    public function rules(): array
    {
        return [];
    }
}
