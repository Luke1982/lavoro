<?php

namespace App\Http\Requests;

use App\Models\StandardEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StandardEmailDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::user()->can('manage', StandardEmail::class);
    }

    public function rules(): array
    {
        return [];
    }
}
