<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPlanGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
