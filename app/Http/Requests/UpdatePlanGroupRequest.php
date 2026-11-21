<?php

namespace App\Http\Requests;

use App\Models\UserPlanGroup;
use App\Rules\HexColor;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', UserPlanGroup::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', new HexColor],
        ];
    }
}
