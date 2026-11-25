<?php

namespace App\Http\Requests;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

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
        return (bool) $this->user()?->can('viewAny', Event::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->user()?->can('seeBeyondCurrentWeek', Event::class)) {
            return;
        }

        $max_end = Carbon::now()->startOfDay()->addDays(7)->endOfDay();

        if (!$this->filled('end') || Carbon::parse($this->input('end'))->gt($max_end)) {
            $this->merge(['end' => $max_end->toIso8601ZuluString()]);
        }
    }

    public function rules(): array
    {
        return [];
    }
}
