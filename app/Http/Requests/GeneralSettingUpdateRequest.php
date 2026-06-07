<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class GeneralSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('settings.update_default_planner_minutes'));
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'integer', 'min:15', 'max:1200'],
        ];
    }
}
