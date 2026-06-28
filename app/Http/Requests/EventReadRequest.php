<?php

namespace App\Http\Requests;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Event index & show authorization.
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
class EventReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('event.read'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->can('seeBeyondCurrentWeek', Event::class)) {
            return;
        }

        $max_end = Carbon::now()->startOfDay()->addDays(7)->endOfDay();

        if (! $this->filled('end') || Carbon::parse($this->input('end'))->gt($max_end)) {
            $this->merge(['end' => $max_end->toIso8601ZuluString()]);
        }
    }

    public function rules(): array
    {
        return [];
    }
}
