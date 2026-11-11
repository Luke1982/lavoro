<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DayRouteDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', $this->route('day_route'));
    }

    public function rules(): array
    {
        return [];
    }
}
