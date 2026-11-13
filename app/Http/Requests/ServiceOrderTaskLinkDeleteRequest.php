<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTaskCheck;
use Illuminate\Foundation\Http\FormRequest;

/** Taking a check or checklist off its list. */
class ServiceOrderTaskLinkDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [];
    }
}
