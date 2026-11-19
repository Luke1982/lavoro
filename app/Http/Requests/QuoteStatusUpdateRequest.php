<?php

namespace App\Http\Requests;

use App\Enums\QuoteStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(QuoteStatuses::class)],
        ];
    }
}
