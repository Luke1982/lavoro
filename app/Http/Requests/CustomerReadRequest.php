<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Customer read (index & show) request.
 *
 * @mixin \Illuminate\Http\Request
 * @method mixed input(string $key = null, $default = null)
 * @property-read string|null $search Optional search term for index.
 */
class CustomerReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('customer.read');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
