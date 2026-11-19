<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use App\Http\Requests\Concerns\ValidatesProjectFields;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class ProjectStoreRequest extends FormRequest
{
    use ValidatesProjectFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    public function rules(): array
    {
        return [
            ...$this->projectFieldRules($this->integer('customer_id') ?: null, $this->mayManageFinancials()),
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'project_manager_id' => ['required', 'exists:users,id', $this->projectLeaderRule()],
            'status' => ['required', 'in:' . ProjectStatuses::validationString()],
            'project_template_id' => ['nullable', 'exists:project_templates,id'],
        ];
    }

    public function messages(): array
    {
        return $this->projectFieldMessages();
    }
}
