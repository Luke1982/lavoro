<?php

namespace App\Services\TaskChecks;

/**
 * Checks picked on top of a task's template: library checks, whole checklists,
 * and titles typed for this werkbon only.
 */
final readonly class PickedExtras
{
    /**
     * @param  array<int, int>  $check_ids
     * @param  array<int, int>  $checklist_ids
     * @param  array<int, string>  $titles
     */
    public function __construct(
        public array $check_ids = [],
        public array $checklist_ids = [],
        public array $titles = [],
    ) {}
}
