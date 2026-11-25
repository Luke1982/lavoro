<?php

namespace App\Http\Requests;

use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;

class StandardEmailReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', StandardEmail::class);
    }

    public function rules(): array
    {
        return [];
    }
}
