<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class GeneralSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user && ($user->isAdmin() || $user->hasPermission('planner.manage_settings'));
    }

    public function rules(): array
    {
        return [
            'value' => match ($this->route('key')) {
                'defaultplannerminutes' => ['required', 'integer', 'min:15', 'max:1200'],
                'planner_leading_color' => ['required', 'string', 'in:event,role'],
                default => ['prohibited'],
            },
        ];
    }
}
