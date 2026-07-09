<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && (
            $user->isAdmin() ||
            $user->hasPermission('serviceorder.read') ||
            $user->hasPermission('serviceorder.read_own')
        );
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'onlyStage' => ['sometimes', 'nullable', 'string'],
            'onlyNeedsClosing' => ['sometimes', 'nullable', 'boolean'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
