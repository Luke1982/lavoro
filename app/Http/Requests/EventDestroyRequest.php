<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event !== null && (bool) $this->user()?->can('delete', $event);
    }

    public function rules(): array
    {
        return [];
    }
}
