<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMilestoneUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('projectmilestone'));
    }

    public function rules(): array
    {
        return [
            'project_id'       => ['sometimes', 'exists:projects,id'],
            'title'            => ['sometimes', 'string', 'max:255'],
            'description'      => ['nullable', 'string'],
            'projected_date'   => ['nullable', 'date'],
            'actual_date'      => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
