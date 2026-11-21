<?php

namespace App\Domain\Signals\Tickets;

use App\Domain\Signals\BaseSignal;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries the priorities themselves rather than a finished sentence, so the
 * change lands as queryable rows and the wording stays this class's business.
 * Told on the storing only: a priority is how urgently it is handled, not news
 * at the customer.
 */
class TicketPriorityChanged extends BaseSignal
{
    public function __construct(
        public Ticket $ticket,
        public ?string $previous_priority,
        public ?string $new_priority,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'ticket.priority_changed';
    }

    public static function label(): string
    {
        return 'Ticketprioriteit gewijzigd';
    }

    public static function coveredFields(): array
    {
        return ['priority'];
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
        return "Prioriteit gewijzigd van '" . $this->previous_priority . "' naar '" . $this->new_priority . "'";
    }

    public function changes(): array
    {
        return [[
            'field' => 'priority',
            'label' => 'Prioriteit',
            'old_value' => $this->previous_priority,
            'new_value' => $this->new_priority,
            'old_label' => $this->previous_priority,
            'new_label' => $this->new_priority,
        ]];
    }
}
