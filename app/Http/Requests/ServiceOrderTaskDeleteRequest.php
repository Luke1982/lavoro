<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('serviceordertask'));
    }

    public function rules(): array
    {
        return [];
    }
}
