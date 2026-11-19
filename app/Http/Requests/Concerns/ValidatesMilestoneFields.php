<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @mixin FormRequest
 */
trait ValidatesMilestoneFields
{
    /**
     * The werkbonnen a milestone waits for come from its own project; a deactivated
     * colleague can no longer be made responsible for one.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function milestoneFieldRules(int $project_id): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string'],
            'projected_date' => ['sometimes', 'nullable', 'date'],
            'actual_date' => ['sometimes', 'nullable', 'date'],
            'assigned_user_id' => [
                'sometimes',
                'nullable',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'service_order_ids' => ['sometimes', 'array'],
            'service_order_ids.*' => [
                'integer',
                Rule::exists('service_orders', 'id')->where('project_id', $project_id),
            ],
        ];
    }
}
