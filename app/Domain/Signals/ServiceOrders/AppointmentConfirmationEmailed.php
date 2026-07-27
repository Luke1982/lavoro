<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

class AppointmentConfirmationEmailed extends BaseSignal
{
    public function __construct(
        public ServiceOrder $service_order,
        public Event $event,
        public array $recipients,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.appointment_confirmation_emailed';
    }

    public static function label(): string
    {
        return 'Afspraakbevestiging verzonden';
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityDescription(): ?string
    {
        return 'Afspraakbevestiging per e-mail verzonden naar: ' . implode(', ', $this->recipients);
    }
}
