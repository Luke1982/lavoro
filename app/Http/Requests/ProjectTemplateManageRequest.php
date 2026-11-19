<?php

namespace App\Http\Requests;

use App\Models\ProjectTemplate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Listing, saving and deleting templates all need the same right; only saving
 * brings fields along.
 */
class ProjectTemplateManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ProjectTemplate::class);
    }

    public function rules(): array
    {
        if (!$this->isMethod('post') && !$this->isMethod('put') && !$this->isMethod('patch')) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'milestones' => ['required', 'array', 'min:1', 'max:100'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.offset_days' => ['required', 'integer', 'min:-3650', 'max:3650'],
        ];
    }

    public function messages(): array
    {
        return [
            'milestones.required' => 'Een sjabloon heeft minstens één mijlpaal.',
            'milestones.*.title.required' => 'Elke mijlpaal heeft een naam.',
            'milestones.*.offset_days.required' => 'Geef bij elke mijlpaal op hoeveel dagen na de start hij valt.',
        ];
    }
}
