<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class FreeformMaterialRemoved extends BaseSignal
{
    /** @param  array<int, Model>  $related  Records this should also surface on. */
    public function __construct(
        public ServiceOrder $service_order,
        public string $item_name,
        public float $quantity,
        public ?string $task_title = null,
        public array $related = [],
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.freeform_material_removed';
    }

    public static function label(): string
    {
        return 'Vrije materiaalregel verwijderd';
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
            'quantity' => $this->quantity,
        ], fn ($value) => $value !== null);
    }

    public function activityDescription(): ?string
    {
        return 'Vrije materiaalregel verwijderd: ' . $this->item_name . ' (aantal ' . $this->quantity . ')' . $this->taskSuffix();
    }

    private function taskSuffix(): string
    {
        return $this->task_title === null ? '' : ' (taak: ' . $this->task_title . ')';
    }
}
