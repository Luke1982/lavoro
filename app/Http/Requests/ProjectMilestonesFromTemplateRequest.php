<?php

namespace App\Http\Requests;

use App\Models\ProjectMilestone;
use Illuminate\Foundation\Http\FormRequest;

class ProjectMilestonesFromTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'))
            && $this->user()->can('create', ProjectMilestone::class);
    }

    public function rules(): array
    {
        return [
            'project_template_id' => ['required', 'exists:project_templates,id'],
        ];
    }
}
