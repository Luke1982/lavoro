<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Product index & show authorization.
 *
 * @property-read string|null $search
 * @property-read int|string|null $onlyType
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method User user()
 * @method Product route(string $key = null)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 * @mixin \Illuminate\Http\Request
 */
class ProductReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('product'));
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyType' => ['sometimes', 'nullable'],
        ];
    }
}
