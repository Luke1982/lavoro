<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProjectMilestoneStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        if ($this->route('projectmilestone')) {
            return $user->isAdmin() || $user->hasPermission('projectmilestone.update');
        }

        return $user->isAdmin() || $user->hasPermission('projectmilestone.create');
    }

    public function rules(): array
    {
        return [
            'project_id'       => ['required', 'exists:projects,id'],
            'title'            => ['required', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'projected_date'   => ['nullable', 'date'],
            'actual_date'      => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
