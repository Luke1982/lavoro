<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use Illuminate\Foundation\Http\FormRequest;

class ListPlanGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
