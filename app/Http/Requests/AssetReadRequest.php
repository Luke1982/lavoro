<?php

namespace App\Http\Requests;

use App\Enums\AssetStatusses;
use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified read request for Assets (index & show).
 *
 * @mixin Request
 *
 * @property-read string|null $search Optional search term
 *
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
        $asset = $this->route('asset');
        if ($asset) {
            return $this->user()->can('view', $asset);
        }

        return $this->user()->can('list', Asset::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(AssetStatusses::class)],
            'tickets' => ['nullable', Rule::in(['any', 'open', 'pending'])],
        ];
    }
}
