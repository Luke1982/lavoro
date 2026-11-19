<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceSignRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('sign', $this->route('serviceordertaskinstance'));
    }

    public function rules(): array
    {
        return [
            'signed_by' => ['required', 'string', 'max:255'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}
