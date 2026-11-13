<?php

namespace App\Http\Requests;

use App\Domain\Macros\MacroDefinition;
use App\Models\ServiceOrderMacro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A macro as built in the editor: its name and its blocks, each checked against its kind. */
class ServiceOrderMacroStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderMacro::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'definition' => ['required', 'array'],
            'definition.steps' => ['present', 'list'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $steps = $this->input('definition.steps');

                if (!is_array($steps) || !array_is_list($steps)) {
                    return;
                }

                foreach ((new MacroDefinition($steps))->errors() as $where => $message) {
                    $validator->errors()->add('definition.' . $where, $message);
                }
            },
        ];
    }
}
