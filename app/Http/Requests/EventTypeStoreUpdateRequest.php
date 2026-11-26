<?php

namespace App\Http\Requests;

use App\Models\EventType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Making an afspraaktype asks for create, changing the one in the route for
 * update. A new one may start without a colour; one in use keeps one.
 */
class EventTypeStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->route('eventtype') ? 'update' : 'create', EventType::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'color' => [$this->route('eventtype') ? 'required' : 'nullable', 'string', 'max:9'],
        ];
    }
}
