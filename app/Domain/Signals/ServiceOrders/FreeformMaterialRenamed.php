<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * A free-text material line was given another description. The line has no history
 * of its own, so without this the werkbon would only ever know its latest wording
 * and "what was this line before" would have no answer.
 */
class FreeformMaterialRenamed extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public string $previous_description,
        public string $new_description,
        public ?string $task_title = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.freeform_material_renamed';
    }

    public static function label(): string
    {
        return 'Vrije materiaalregel omschrijving gewijzigd';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityCategory(): string
    {
        return 'material';
    }

    public function activityMetadata(): ?array
    {
        return array_filter([
            'item_name' => $this->new_description,
            'task_title' => $this->task_title,
        ], fn ($value) => $value !== null);
    }

    public function activityDescription(): ?string
    {
        return 'Vrije materiaalregel hernoemd van \'' . $this->previous_description
            . '\' naar \'' . $this->new_description . '\'' . $this->taskSuffix();
    }

    public function changes(): array
    {
        return [[
            'field' => 'description',
            'label' => 'Omschrijving',
            'old_value' => $this->previous_description,
            'new_value' => $this->new_description,
            'old_label' => $this->previous_description,
            'new_label' => $this->new_description,
        ]];
    }

    private function taskSuffix(): string
    {
        return $this->task_title === null ? '' : ' (taak: ' . $this->task_title . ')';
    }
}
