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

    public function activityContext(): array
    {
        return $this->new_stage ? [$this->new_stage] : [];
    }

    public function activityMetadata(): ?array
    {
        return [
            'previous_stage_id' => $this->previous_stage?->id,
            'new_stage_id' => $this->new_stage?->id,
        ];
    }
}
