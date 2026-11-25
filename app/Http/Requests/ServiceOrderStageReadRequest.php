<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderStage;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderStageReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', ServiceOrderStage::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
