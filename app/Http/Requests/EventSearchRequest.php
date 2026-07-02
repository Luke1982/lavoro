<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class EventSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Event::class);
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}
