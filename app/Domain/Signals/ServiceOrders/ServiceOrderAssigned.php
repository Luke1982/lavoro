<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Users were put on a werkbon. Carries only the users who are newly on it, so a
 * subscriber never has to work out who already knew.
 */
class ServiceOrderAssigned extends BaseSignal implements ServiceOrderSignal
{
    /** @param  array<int, int>  $newly_assigned_user_ids */
    public function __construct(
        public ServiceOrder $service_order,
        public array $newly_assigned_user_ids,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.assigned';
    }

    public static function label(): string
    {
        return 'Werkbon toegewezen';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}
