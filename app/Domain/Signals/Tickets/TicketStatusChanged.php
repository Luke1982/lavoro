<?php

namespace App\Domain\Signals\Tickets;

use App\Domain\Signals\BaseSignal;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries the statuses themselves rather than a finished sentence, so the change
 * lands as queryable rows and the wording stays this class's business.
 */
class TicketStatusChanged extends BaseSignal
{
    public function __construct(
        public Ticket $ticket,
        public ?string $previous_status,
        public ?string $new_status,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'ticket.status_changed';
    }

    public static function label(): string
    {
        return 'Ticketstatus gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['status'];
    }

    public function subject(): Model
    {
        return $this->ticket;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return "Status gewijzigd van '" . $this->previous_status . "' naar '" . $this->new_status . "'";
    }

    public function changes(): array
    {
        return [[
            'field' => 'status',
            'label' => 'Status',
            'old_value' => $this->previous_status,
            'new_value' => $this->new_status,
            'old_label' => $this->previous_status,
            'new_label' => $this->new_status,
        ]];
    }
}
