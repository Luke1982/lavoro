<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceUnsignRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('unsign', $this->route('serviceordertaskinstance'));
    }

    public function rules(): array
    {
        return [];
    }
}
