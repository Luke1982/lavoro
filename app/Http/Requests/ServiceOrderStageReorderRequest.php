<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('serviceorderstage.update'));
    }

    public function rules(): array
    {
        return [
            'payload'         => ['required', 'array'],
            'payload.*.id'    => ['required', 'integer', 'exists:service_order_stages,id'],
            'payload.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
