<?php

namespace App\Http\Requests\Tray;

use App\Domain\Tray\Tray;
use App\Models\TrayItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A paste off the bakje. Which bakje items, where they were dropped and, after
 * the person has been asked, what they chose.
 *
 * Whether the paste itself is allowed is decided per record by the paste, with
 * the same rights as doing it by hand: the bakje is a quicker way to the same
 * door, not a second door.
 */
class TrayPasteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('paste', TrayItem::class);
    }

    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer'],
            'target' => ['required', 'array'],
            'target.type' => ['required', 'string', Rule::in(Tray::targets())],
            'target.id' => ['exclude_if:target.type,planner', 'required', 'integer'],
            'target.user_id' => ['exclude_unless:target.type,planner', 'required', 'integer', 'exists:users,id'],
            'target.start' => ['exclude_unless:target.type,planner', 'required', 'date_format:Y-m-d H:i'],
            'target.end' => ['exclude_unless:target.type,planner', 'required', 'date_format:Y-m-d H:i', 'after:target.start'],
            'target.event_type_id' => ['exclude_unless:target.type,planner', 'required', 'integer', 'exists:event_types,id'],
            'answer' => ['nullable', 'string', 'max:50'],
            'remove' => ['sometimes', 'boolean'],
        ];
    }
}
