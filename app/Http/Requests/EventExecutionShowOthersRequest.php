<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventExecutionShowOthersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOthers', [$this->route('event'), (int) $this->route('target_user')->id]);
    }

    public function rules(): array
    {
        return [];
    }
}
