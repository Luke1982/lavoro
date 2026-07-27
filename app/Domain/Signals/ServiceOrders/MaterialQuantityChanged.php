<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class MaterialQuantityChanged extends BaseSignal
{
    /** @param  array<int, Model>  $related  Records this should also surface on. */
    public function __construct(
        public ServiceOrder $service_order,
        public string $item_name,
        public ?float $previous_quantity,
        public float $new_quantity,
        public ?string $task_title = null,
        public array $related = [],
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.material_quantity_changed';
    }

    public static function label(): string
    {
        return 'Materiaalaantal gewijzigd';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityCategory(): string
    {
        return 'material';
    }

    public function activityContext(): array
    {
        return $this->related;
    }

    public function activityMetadata(): ?array
    {
        return array_filter([
            'item_name' => $this->item_name,
            'task_title' => $this->task_title,
            'previous_quantity' => $this->previous_quantity,
            'new_quantity' => $this->new_quantity,
        ], fn ($value) => $value !== null);
    }

    public function activityDescription(): ?string
    {
        return 'Materiaal hoeveelheid aangepast: ' . $this->item_name . ' naar ' . $this->new_quantity . $this->taskSuffix();
    }

    public function changes(): array
    {
        return [[
            'field' => 'quantity',
            'label' => 'Aantal',
            'old_value' => $this->previous_quantity === null ? null : (string) $this->previous_quantity,
            'new_value' => (string) $this->new_quantity,
            'old_label' => $this->previous_quantity === null ? null : (string) $this->previous_quantity,
            'new_label' => (string) $this->new_quantity,
        ]];
    }

    private function taskSuffix(): string
    {
        return $this->task_title === null ? '' : ' (taak: ' . $this->task_title . ')';
    }
}
