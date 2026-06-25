<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTask;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('read', ServiceOrderTask::class);
    }

    public function rules(): array
    {
        return [
            'search'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
