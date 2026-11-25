<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', ServiceOrder::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyStage' => ['sometimes', 'nullable', 'string'],
            'onlyNeedsClosing' => ['sometimes', 'nullable', 'boolean'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
