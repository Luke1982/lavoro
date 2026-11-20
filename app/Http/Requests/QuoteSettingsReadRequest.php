<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteSettingsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [];
    }
}
