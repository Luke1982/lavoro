<?php

namespace App\Domain\Signals\Tickets;

use App\Domain\Signals\BaseSignal;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Carries the statuses themselves rather than a finished sentence, so the change
 * lands as queryable rows and the wording stays this class's business.
 *
 * The reason is set when the app moved the status by itself, so the trail says
 * why a storing changed while nobody touched it.
 */
class TicketStatusChanged extends BaseSignal
{
    use ReachesTheTicketsCustomer;

    public function __construct(
        public Ticket $ticket,
        public ?string $previous_status,
        public ?string $new_status,
        public ?string $reason = null,
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
        $sentence = "Status gewijzigd van '" . $this->previous_status . "' naar '" . $this->new_status . "'";

        return $this->reason === null ? $sentence : $sentence . ', ' . $this->reason;
    }

    public function activityMetadata(): ?array
    {
        return $this->withTicketLabel($this->reason === null ? [] : ['reason' => $this->reason]);
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
