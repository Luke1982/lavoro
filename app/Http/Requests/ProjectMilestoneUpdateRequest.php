<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMilestoneFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A milestone stays on the project it was made on: its history is that project's.
 */
class ProjectMilestoneUpdateRequest extends FormRequest
{
    use ValidatesMilestoneFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('projectmilestone'));
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            ...$this->milestoneFieldRules($this->route('projectmilestone')->project_id),
        ];
    }
}
