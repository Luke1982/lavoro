<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventTypeSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isAdmin() || $user->hasPermission('eventtype.read'));
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}
