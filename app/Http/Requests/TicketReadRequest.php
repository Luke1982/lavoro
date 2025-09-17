<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Ticket read authorization & (optional) filter parameters.
 *
 * @mixin \Illuminate\Http\Request
 * @property-read string|null $search
 * @property-read string|null $statuses CSV statuses
 * @property-read string|null $priorities CSV priorities
 * @method array validated()
 * @method mixed input(string $key = null, $default = null)
 * @method bool has(string $key)
 * @method bool filled(string $key)
 * @method mixed get(string $key, $default = null)
 * @method array all($keys = null)
 * @method mixed query(string $key = null, $default = null)
 */
class TicketReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('ticket.read'));
    }

    public function rules(): array
    {
        return [
            'search'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'statuses'   => ['sometimes', 'nullable', 'string'], // CSV of enum names
            'priorities' => ['sometimes', 'nullable', 'string'], // CSV of enum names
        ];
    }
}
