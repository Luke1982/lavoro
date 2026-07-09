<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventExecutionUpdateOthersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOthers', [$this->route('event'), (int) $this->route('target_user')->id]);
    }

    public function rules(): array
    {
        return [
            'actual_start' => ['required', 'date'],
            'actual_end' => ['required', 'date', 'after:actual_start'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}
