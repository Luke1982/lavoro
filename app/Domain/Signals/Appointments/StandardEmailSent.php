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
}
