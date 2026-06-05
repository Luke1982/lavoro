<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $user  = $this->user();

        if (! $user || ! $event) return false;
        if ($user->isAdmin() || $user->hasPermission('event.delete_others')) return true;

        return $user->hasPermission('event.delete') && $event->hasExecutingUser($user->id);
    }

    public function rules(): array
    {
        return [];
    }
}
