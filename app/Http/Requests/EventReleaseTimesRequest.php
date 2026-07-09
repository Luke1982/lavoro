<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventReleaseTimesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('releaseTimes', $this->route('event'));
    }

    public function rules(): array
    {
        return [];
    }
}
