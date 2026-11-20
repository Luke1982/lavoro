<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteLostReasonsOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'reason_ids' => ['required', 'array'],
            'reason_ids.*' => ['integer', 'distinct', 'exists:quote_lost_reasons,id'],
        ];
    }
}
