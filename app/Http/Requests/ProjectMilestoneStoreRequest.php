<?php

namespace App\Http\Requests;

use App\Models\ProjectMilestone;
use Illuminate\Foundation\Http\FormRequest;

class ProjectMilestoneStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProjectMilestone::class);
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
