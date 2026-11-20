<?php

namespace App\Http\Requests;

use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

class QuoteConvertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'))
            && $this->user()->can('create', ServiceOrder::class);
    }

    public function rules(): array
    {
        return [];
    }
}
