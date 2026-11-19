<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use App\Http\Requests\Concerns\ValidatesProjectFields;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The project page saves one field at a time, so a date is checked against the
 * other date as it is stored when only one of them comes along. Otherwise an end
 * date before the start slips through on the page that shows both.
 */
class ProjectUpdateRequest extends FormRequest
{
    use ValidatesProjectFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->project());
    }

    public function rules(): array
    {
        $project = $this->project();
        $customer_id = $this->has('customer_id') ? ($this->integer('customer_id') ?: null) : $project->customer_id;
        $start = $this->has('start_date') ? $this->input('start_date') : $project->start_date;
        $end = $this->has('end_date') ? $this->input('end_date') : $project->end_date;

        return [
            ...$this->projectFieldRules($customer_id, $this->mayManageFinancials($project)),
            'title' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['nullable', 'date', ...(filled($end) && !$this->has('end_date') ? ['before_or_equal:' . $end] : [])],
            'end_date' => ['nullable', 'date', ...(filled($start) ? ['after_or_equal:' . $start] : [])],
            'customer_id' => ['sometimes', 'exists:customers,id'],
            'project_manager_id' => ['sometimes', 'exists:users,id', $this->projectLeaderRule()],
            'status' => ['sometimes', 'in:' . ProjectStatuses::validationString()],
        ];
    }

    public function messages(): array
    {
        return $this->projectFieldMessages();
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
