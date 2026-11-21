<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use App\Rules\HexColor;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', new HexColor],
        ];
    }
}
