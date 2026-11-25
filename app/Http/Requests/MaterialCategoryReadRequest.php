<?php

namespace App\Http\Requests;

use App\Models\MaterialCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * MaterialCategory index & show authorization.
 *
 * @mixin Request
 *
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 */
class MaterialCategoryReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', MaterialCategory::class);
    }

    public function rules(): array
    {
        return [];
    }
}
