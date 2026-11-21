<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPlanGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer', 'exists:user_plan_groups,id'],
        ];
    }
}
