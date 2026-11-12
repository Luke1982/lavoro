<?php

namespace App\Http\Requests\Admin;

use App\Rules\HexColor;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMapDueColorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'expired' => ['required', new HexColor],
            'later' => ['required', new HexColor],
            'bands' => ['present', 'array', 'max:20'],
            'bands.*.days' => ['required', 'integer', 'min:1', 'distinct'],
            'bands.*.color' => ['required', new HexColor],
        ];
    }

    public function attributes(): array
    {
        return [
            'expired' => 'kleur voor verlopen',
            'later' => 'kleur voor later',
            'bands' => 'groepen',
            'bands.*.days' => 'aantal dagen',
            'bands.*.color' => 'kleur',
        ];
    }
}
