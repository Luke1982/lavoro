<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteLinesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1'],
            'line_ids.*' => ['integer', 'distinct', Rule::exists('quote_lines', 'id')->where('quote_id', $this->route('quote')->id)],
        ];
    }
}
