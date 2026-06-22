<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class EventExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('export', Event::class);
    }

    public function rules(): array
    {
        return [
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'tz' => ['nullable', 'string', 'timezone'],
        ];
    }
}
