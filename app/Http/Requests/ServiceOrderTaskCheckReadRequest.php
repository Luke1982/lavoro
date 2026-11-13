<?php

namespace App\Http\Requests;

use App\Models\ServiceOrderTaskCheck;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskCheckReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('read', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [];
    }
}
