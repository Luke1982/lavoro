<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isAdmin() || $user->hasPermission('event.create'));
    }

    public function rules(): array
    {
        return [
            'offsets'   => 'required|array|min:1',
            'offsets.*' => 'required|integer|min:1|max:365',
        ];
    }
}
