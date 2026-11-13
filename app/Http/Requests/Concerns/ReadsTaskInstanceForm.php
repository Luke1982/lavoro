<?php

namespace App\Http\Requests\Concerns;

use App\Services\TaskChecks\PickedExtras;

/**
 * The task form on a werkbon: the task's own fields, its roles and bundle
 * aantallen, and the extra checks picked on top of its template.
 */
trait ReadsTaskInstanceForm
{
    private const EXTRA_KEYS = ['extra_check_ids', 'extra_checklist_ids', 'extra_titles'];

    /** @return array<string, array<int, string>> */
    protected function extraCheckRules(): array
    {
        return [
            'extra_check_ids' => ['sometimes', 'array'],
            'extra_check_ids.*' => ['integer', 'exists:service_order_task_checks,id'],
            'extra_checklist_ids' => ['sometimes', 'array'],
            'extra_checklist_ids.*' => ['integer', 'exists:service_order_task_checklists,id'],
            'extra_titles' => ['sometimes', 'array'],
            'extra_titles.*' => ['string', 'max:255'],
        ];
    }

    /** Null when the form sent no extras at all, so an edit that leaves them alone does not clear them. */
    public function extraChecks(): ?PickedExtras
    {
        if (!$this->hasAny(self::EXTRA_KEYS)) {
            return null;
        }

        return new PickedExtras(
            array_map('intval', $this->input('extra_check_ids', [])),
            array_map('intval', $this->input('extra_checklist_ids', [])),
            $this->input('extra_titles', []),
        );
    }

    /**
     * The validated fields that are columns of the task itself.
     *
     * @return array<string, mixed>
     */
    public function taskColumns(): array
    {
        return collect($this->validated())->except([...self::EXTRA_KEYS, 'user_role_ids', 'flex_parts'])->all();
    }
}
