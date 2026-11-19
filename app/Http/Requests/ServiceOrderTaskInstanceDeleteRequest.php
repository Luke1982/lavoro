<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceDeleteRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('delete', $this->route('serviceordertaskinstance'));
    }

    public function rules(): array
    {
        return [];
    }
}
