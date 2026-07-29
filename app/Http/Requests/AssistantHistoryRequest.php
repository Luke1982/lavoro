<?php

namespace App\Http\Requests;

use App\Models\Assistant;
use Illuminate\Foundation\Http\FormRequest;

class AssistantHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use', Assistant::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
