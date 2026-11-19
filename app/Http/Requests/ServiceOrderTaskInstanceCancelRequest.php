<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceCancelRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('cancel', $this->route('serviceordertaskinstance'));
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
