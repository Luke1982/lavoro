<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use Illuminate\Foundation\Http\FormRequest;

class SyncUserPlanGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', 'exists:user_plan_groups,id'],
        ];
    }
}
