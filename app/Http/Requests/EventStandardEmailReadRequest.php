<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventStandardEmailReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sendStandardEmail', $this->route('event'));
    }

    public function rules(): array
    {
        return [];
    }
}
