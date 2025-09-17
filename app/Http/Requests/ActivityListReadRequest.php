<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Activity list (upcoming activities & map) read authorization.
 *
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 * @mixin \Illuminate\Http\Request
 */
class ActivityListReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('activitylist.read'));
    }

    public function rules(): array
    {
        return [
            'days' => ['sometimes','integer','min:1','max:365'],
        ];
    }
}
