<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventTypeSearchRequest extends FormRequest
{
    /**
     * Het soort afspraak kiezen hoort bij het maken van een afspraak, niet bij het
     * beheren van de soorten.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user || $user->isAdmin()) {
            return (bool) $user;
        }

        foreach (['eventtype.read', 'event.create', 'event.update'] as $permission) {
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
