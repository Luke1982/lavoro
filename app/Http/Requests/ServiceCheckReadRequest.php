<?php

namespace App\Http\Requests;

use App\Models\ServiceCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * ServiceCheck index & show authorization.
 *
 * @property-read string|null $search
 * @property-read int|string|null $onlyType
 *
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 *
 * @mixin Request
 */
class ServiceCheckReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', ServiceCheck::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyType' => ['sometimes', 'nullable'],
        ];
    }
}
