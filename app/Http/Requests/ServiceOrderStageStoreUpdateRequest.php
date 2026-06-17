<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ServiceOrderStageStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $permission = $this->isMethod('post') ? 'serviceorderstage.create' : 'serviceorderstage.update';

        return $user && ($user->isAdmin() || $user->hasPermission($permission));
    }

    public function rules(): array
    {
        $stage = $this->route('serviceorderstage');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_planned_state' => [
                'sometimes',
                'boolean',
                function ($attribute, $value, $fail) use ($stage) {
                    if (! $value) {
                        return;
                    }
                    $effective = $this->has('is_planning_cancelled_state')
                        ? $this->boolean('is_planning_cancelled_state')
                        : ($stage?->is_planning_cancelled_state ?? false);
                    if ($effective) {
                        $fail('Een fase kan niet tegelijk de geplande en de geannuleerde fase zijn.');
                    }
                },
            ],
            'is_closed_state' => ['sometimes', 'boolean'],
            'is_invoiced_state' => ['sometimes', 'boolean'],
            'is_plannable_state' => ['sometimes', 'boolean'],
            'is_planning_cancelled_state' => [
                'sometimes',
                'boolean',
                function ($attribute, $value, $fail) use ($stage) {
                    if (! $value) {
                        return;
                    }
                    $effective = $this->has('is_planned_state')
                        ? $this->boolean('is_planned_state')
                        : ($stage?->is_planned_state ?? false);
                    if ($effective) {
                        $fail('Een fase kan niet tegelijk de geplande en de geannuleerde fase zijn.');
                    }
                },
            ],
        ];
    }
}
