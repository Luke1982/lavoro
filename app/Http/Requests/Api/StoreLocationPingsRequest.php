<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationPingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'pings'               => ['required', 'array', 'min:1', 'max:200'],
            'pings.*.lat'         => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng'         => ['required', 'numeric', 'between:-180,180'],
            'pings.*.accuracy'    => ['nullable', 'numeric', 'min:0'],
            'pings.*.speed'       => ['nullable', 'numeric', 'min:0'],
            'pings.*.heading'     => ['nullable', 'numeric', 'between:0,360'],
            'pings.*.recorded_at' => ['required', 'date'],
        ];
    }
}
