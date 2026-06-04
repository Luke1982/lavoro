<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserUnavailabilityDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('unavailability'));
    }

    public function rules(): array
    {
        return [];
    }
}
