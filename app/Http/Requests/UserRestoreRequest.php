<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('restore', $this->route('user'));
    }

    public function rules(): array
    {
        return [];
    }
}
