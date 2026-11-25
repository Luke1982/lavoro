<?php

namespace App\Http\Requests;

use App\Models\MaterialUsageUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * MaterialUsageUnit index & show authorization.
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
class MaterialUsageUnitReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', MaterialUsageUnit::class);
    }

    public function rules(): array
    {
        return [];
    }
}
