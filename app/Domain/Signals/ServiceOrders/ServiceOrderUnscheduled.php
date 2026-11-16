<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment went off a werkbon: deleted, cancelled, moved to another werkbon
 * or to none. Other appointments may still be on it; this says only that this
 * one is gone.
 *
 * Leaves no line of its own, for the same reason as ServiceOrderScheduled.
 */
class ServiceOrderUnscheduled extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public Event $appointment,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.unscheduled';
    }

    public static function label(): string
    {
        return 'Afspraak van werkbon gehaald';
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
