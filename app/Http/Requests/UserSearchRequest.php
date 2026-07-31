<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserSearchRequest extends FormRequest
{
    /**
     * Wie mag inplannen moet kunnen kiezen wie er gaat. Alleen user.read is te smal:
     * geen van de rollen hier heeft dat, en dan blijft de lijst leeg.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user || $user->isAdmin()) {
            return (bool) $user;
        }

        foreach (['user.read', 'event.create', 'event.update'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}
