<?php

namespace App\Http\Requests;

use App\Models\DayRoute;
use Illuminate\Foundation\Http\FormRequest;

class DayRouteIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', DayRoute::class);
    }

    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }
}
