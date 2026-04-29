<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ProjectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    public function rules(): array
    {
        return [
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
            'customer_id'        => ['required', 'exists:customers,id'],
            'project_manager_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $can_lead_project = User::query()->canLeadProjects()->whereKey($value)->exists();
                    if (!$can_lead_project) {
                        $fail('De geselecteerde projectleider mag geen projecten leiden.');
                    }
                },
            ],
            'status'             => ['required', 'in:' . ProjectStatuses::validationString()],
        ];
    }
}
