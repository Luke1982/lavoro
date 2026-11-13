<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskCheck;
use App\Models\ServiceOrderTaskChecklist;
use App\Models\ServiceOrderTaskInstanceCheck;
use Carbon\CarbonInterface;

/**
 * One check as a werkbon task carries it: the title it shows, the checklist it
 * came with, whether it must be ticked, and whether it was picked on top of the
 * template. The library ids are kept to recognise the check again, never to show.
 */
final readonly class CheckItem
{
    public function __construct(
        public ?int $check_id,
        public ?int $checklist_id,
        public string $title,
        public ?string $checklist_title,
        public bool $is_required,
        public bool $is_extra,
    ) {}

    public static function fromLibrary(
        ServiceOrderTaskCheck $check,
        ?ServiceOrderTaskChecklist $checklist,
        bool $is_required,
        bool $is_extra = false,
    ): self {
        return new self($check->id, $checklist?->id, $check->title, $checklist?->title, $is_required, $is_extra);
    }

    /** A check typed for one werkbon only: optional, and never in the library. */
    public static function typed(string $title): self
    {
        return new self(null, null, $title, null, false, true);
    }

    public static function fromCopy(ServiceOrderTaskInstanceCheck $check): self
    {
        return new self(
            $check->service_order_task_check_id,
            $check->service_order_task_checklist_id,
            $check->title,
            $check->checklist_title,
            $check->is_required,
            $check->is_extra,
        );
    }

    public function withRequired(bool $is_required): self
    {
        return new self(
            $this->check_id,
            $this->checklist_id,
            $this->title,
            $this->checklist_title,
            $is_required,
            $this->is_extra,
        );
    }

    /**
     * What makes two copies the same check: the checklist it came with, and the
     * library check or, for a typed one, its title.
     */
    public function key(): string
    {
        $what = $this->check_id ? 'check:' . $this->check_id : 'title:' . $this->title;

        return $this->checklist_id ? 'list:' . $this->checklist_id . ':' . $what : $what;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(int $instance_id, ?int $unit_index, int $sort_order, CarbonInterface $now): array
    {
        return [
            'service_order_task_instance_id' => $instance_id,
            'service_order_task_check_id' => $this->check_id,
            'service_order_task_checklist_id' => $this->checklist_id,
            'title' => $this->title,
            'checklist_title' => $this->checklist_title,
            'is_required' => $this->is_required,
            'is_extra' => $this->is_extra,
            'is_done' => false,
            'unit_index' => $unit_index,
            'sort_order' => $sort_order,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
