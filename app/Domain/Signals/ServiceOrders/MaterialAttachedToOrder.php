<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\Materials\ReportsStock;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class MaterialAttachedToOrder extends BaseSignal
{
    use ReportsStock;

    /**
     * @param  array<int, Model>  $related  Records this should also surface on.
     * @param  ?float  $stock_before  The material's stock before this booking moved it.
     * @param  ?float  $stock_after  Its stock after.
     */
    public function __construct(
        public ServiceOrder $service_order,
        public string $item_name,
        public float $quantity,
        public ?string $task_title = null,
        public array $related = [],
        public ?float $stock_before = null,
        public ?float $stock_after = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.material_attached';
    }

    public static function label(): string
    {
        return 'Materiaal toegevoegd aan werkbon';
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
            'service_order_id' => $this->service_order->getKey(),
            'service_order_number' => $this->service_order->number,
        ], fn ($value) => $value !== null);
    }

    public function activityDescription(): ?string
    {
        return 'Materiaal toegevoegd: ' . $this->item_name . ' (aantal ' . $this->quantity . ')' . $this->taskSuffix();
    }

    public function changes(): array
    {
        return self::stockChanges($this->stock_before, $this->stock_after);
    }

    private function taskSuffix(): string
    {
        return $this->task_title === null ? '' : ' (taak: ' . $this->task_title . ')';
    }
}
