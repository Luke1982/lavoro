<?php

namespace App\Http\Requests;

use App\Enums\ProjectStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProjectStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        if ($this->route('project')) {
            return $user->isAdmin() || $user->hasPermission('project.update');
        }

        return $user->isAdmin() || $user->hasPermission('project.create');
    }

    public function rules(): array
    {
        $valid_statuses = implode(',', array_map(
            fn($s) => $s->value,
            ProjectStatuses::cases()
        ));

        return [
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
            'customer_id'        => ['required', 'exists:customers,id'],
            'project_manager_id' => ['required', 'exists:users,id'],
            'status'             => ['required', 'in:' . $valid_statuses],
        ];
    }
}
