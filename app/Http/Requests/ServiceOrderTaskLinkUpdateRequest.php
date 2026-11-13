<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTaskCheck;
use Illuminate\Foundation\Http\FormRequest;

/** Making a link between a check or checklist and its list required or optional. */
class ServiceOrderTaskLinkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [
            'is_required' => ['required', 'boolean'],
        ];
    }
}
