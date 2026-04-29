<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'title'              => ['sometimes', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date'],
            'customer_id'        => ['sometimes', 'exists:customers,id'],
            'project_manager_id' => [
                'sometimes',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $can_lead_project = User::query()->canLeadProjects()->whereKey($value)->exists();
                    if (!$can_lead_project) {
                        $fail('De geselecteerde projectleider mag geen projecten leiden.');
                    }
                },
            ],
            'status'             => ['sometimes', 'in:' . ProjectStatuses::validationString()],
        ];
    }
}
