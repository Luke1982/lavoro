<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Unified read request for Assets (index & show).
 *
 * @mixin \Illuminate\Http\Request
 * @property-read string|null $search Optional search term
 * @method array validated() Return validated data
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method User user()
 * @method Asset route(string $key = null)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 */
class AssetReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('asset'));
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
