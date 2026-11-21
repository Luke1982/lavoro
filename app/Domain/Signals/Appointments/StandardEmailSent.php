<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\StandardEmail;
use Illuminate\Database\Eloquent\Model;

class StandardEmailSent extends BaseSignal
{
    public function __construct(
        public Event $event,
        public StandardEmail $standard_email,
        public string $recipient,
        public string $subject_line,
        public ?string $trigger,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.standard_email_sent';
    }

    public static function label(): string
    {
        return 'Standaard e-mail verzonden';
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityDescription(): ?string
    {
        return sprintf("Standaard e-mail '%s' verzonden aan %s", $this->standard_email->name, $this->recipient);
    }

    /**
     * What the appointment's e-mail history reads back: which standard e-mail
     * went to whom, so it can offer to send that one again.
     */
    public function activityMetadata(): ?array
    {
        return [
            'standard_email_id' => $this->standard_email->id,
            'to' => $this->recipient,
            'subject' => $this->subject_line,
            'trigger' => $this->trigger,
        ];
    }

    /**
     * The customer received it, so it is on the customer's timeline too, and on
     * the werkbonnen the appointment is for.
     *
     * @return array<int, Model>
     */
    public function activityContext(): array
    {
        if (!$this->event->exists) {
            return [];
        }

        $service_orders = $this->event->serviceOrders()->with('customer')->get();

        return [
            ...$service_orders->all(),
            ...$service_orders->pluck('customer')->filter()->all(),
            ...$this->event->customers()->get()->all(),
        ];
    }
}
