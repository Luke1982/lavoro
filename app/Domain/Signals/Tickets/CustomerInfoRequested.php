<?php

namespace App\Domain\Signals\Tickets;

use App\Domain\Signals\BaseSignal;
use App\Models\Ticket;
use App\Services\TicketInfoRequestRenderer;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Er is een klant om aanvullende informatie gevraagd, met een link erbij.
 *
 * Draagt waarom gevraagd is en aan wie, want dat is wat een collega later wil
 * weten: niet dat er "een mail uit is", maar wat er gevraagd is en waar het
 * naartoe ging.
 */
class CustomerInfoRequested extends BaseSignal
{
    /** @param  array<int, string>  $requested */
    public function __construct(
        public Ticket $ticket,
        public string $recipient,
        public array $requested,
        public ?DateTimeInterface $expires_at = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'ticket.info_requested';
    }

    public static function label(): string
    {
        return 'Aanvullende informatie opgevraagd';
    }

    public function subject(): Model
    {
        return $this->ticket;
    }

    public function activityCategory(): string
    {
        return 'email';
    }

    public function activityDescription(): ?string
    {
        return 'Aanvullende informatie opgevraagd' . $this->about()
            . ' — verstuurd aan ' . $this->recipient;
    }

    public function activityMetadata(): ?array
    {
        return [
            'to' => $this->recipient,
            'requested' => $this->requested,
            'expires_at' => $this->expires_at?->format(DateTimeInterface::ATOM),
        ];
    }

    /** Waarom gevraagd is, in de woorden waarin de klant het gelezen heeft. */
    private function about(): string
    {
        $labels = TicketInfoRequestRenderer::labelsFor($this->requested);

        return $labels === [] ? '' : ' (' . implode(', ', $labels) . ')';
    }
}
