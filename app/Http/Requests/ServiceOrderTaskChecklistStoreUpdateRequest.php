<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTaskCheck;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskChecklistStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
