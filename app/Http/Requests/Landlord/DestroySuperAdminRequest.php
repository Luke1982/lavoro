<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * A request of its own, because a deletion sends no email address along.
 * Reusing the create request makes the deletion strand on "email address is
 * required".
 */
class DestroySuperAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('landlord')->check();
    }

    public function rules(): array
    {
        return [];
    }
}
