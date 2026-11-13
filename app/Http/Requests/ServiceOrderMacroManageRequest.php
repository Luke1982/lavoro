<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderMacro;
use Illuminate\Foundation\Http\FormRequest;

/** Opening the macro list or editor, or deleting a macro. */
class ServiceOrderMacroManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderMacro::class);
    }

    public function rules(): array
    {
        return [];
    }
}
