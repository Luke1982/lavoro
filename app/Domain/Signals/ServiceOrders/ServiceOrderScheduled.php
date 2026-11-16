<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment came onto a werkbon: made for it, moved onto it, copied to
 * another day, brought back after a delete, or no longer cancelled. Moving an
 * appointment that already hung off this werkbon in time is not a new fact.
 *
 * Leaves no line of its own: the werkbon's timeline already shows its
 * appointments, so a sentence here would say the same thing twice.
 */
class ServiceOrderScheduled extends BaseSignal implements ServiceOrderSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public Event $appointment,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.scheduled';
    }

    public static function label(): string
    {
        return 'Werkbon ingepland';
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
