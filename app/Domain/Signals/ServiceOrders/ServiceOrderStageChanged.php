<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderStageChanged extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public ?ServiceOrderStage $previous_stage,
        public ?ServiceOrderStage $new_stage,
        public ?string $reason = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.stage_changed';
    }

    public static function label(): string
    {
        return 'Werkbon fase gewijzigd';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public static function coveredFields(): array
    {
        return ['service_order_stage_id', 'closed_on'];
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        if (!$this->new_stage) {
            return 'Fase verwijderd';
        }

        $suffix = $this->reason ? ' (' . $this->reason . ')' : '';

        return 'Fase gewijzigd naar: ' . $this->new_stage->name . $suffix;
    }

    public function activityCategory(): string
    {
        return 'stage';
    }

    /**
     * On the stage it went to, and on the customer when the step closes the werkbon or
     * opens a closed one again: a werkbon finished, or taken up again after it was, is
     * news there; every step in between is not. The customer is looked up rather than
     * read from the relation, which a caller may have loaded before moving the werkbon
     * to someone else.
     */
    public function activityContext(): array
    {
        $closed_before = $this->previous_stage?->closesOrder() === true;
        $closed_now = $this->new_stage?->closesOrder() === true;
        $customer = $closed_before !== $closed_now ? $this->service_order->customer()->first() : null;

        return array_values(array_filter([$this->new_stage, $customer]));
    }

    public function changes(): array
    {
        return [[
            'field' => 'service_order_stage_id',
            'label' => 'Fase',
            'old_value' => $this->previous_stage?->getKey() === null ? null : (string) $this->previous_stage->getKey(),
            'new_value' => $this->new_stage?->getKey() === null ? null : (string) $this->new_stage->getKey(),
            'old_label' => $this->previous_stage?->name,
            'new_label' => $this->new_stage?->name,
        ]];
    }

    public function activityMetadata(): ?array
    {
        return [
            'previous_stage_id' => $this->previous_stage?->id,
            'new_stage_id' => $this->new_stage?->id,
        ];
    }
}
