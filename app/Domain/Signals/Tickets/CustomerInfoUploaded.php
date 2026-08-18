<?php

namespace App\Domain\Signals\Tickets;

use App\Domain\Signals\BaseSignal;
use App\Domain\Tickets\CustomerUploadResult;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Een klant heeft via de aanleverlink iets opgestuurd.
 *
 * De handelende partij is hier geen gebruiker en ook niet het systeem, dus de
 * afzender wordt na de constructor overschreven: 'customer', met de naam van de
 * klant en het adres waar de link naartoe ging. Dat adres staat erbij omdat één
 * klant meerdere aanvragen kan hebben lopen en de tijdlijn anders niet zegt via
 * welke er geleverd is.
 *
 * Eén melding per inzending en niet per bestand: wie tien foto's stuurt heeft één
 * ding gedaan.
 */
class CustomerInfoUploaded extends BaseSignal
{
    public function __construct(
        public Ticket $ticket,
        public CustomerUploadResult $delivered = new CustomerUploadResult,
        public ?string $customer_name = null,
        public ?string $recipient = null,
    ) {
        parent::__construct();

        $this->actor_id = null;
        $this->actor_type = 'customer';
        $this->actor_name = $this->customerLabel();
    }

    public static function key(): string
    {
        return 'ticket.customer_uploaded';
    }

    public static function label(): string
    {
        return 'Klant leverde informatie aan';
    }

    public function subject(): Model
    {
        return $this->ticket;
    }

    /**
     * De soort waar het beeld bij hoort, want daar hangt het pictogram aan. Foto's
     * wegen het zwaarst: die zijn waar het om gevraagd is en die laten zich als
     * voorbeeldplaatje zien. Zonder beeld is het een document, en zonder dat alles
     * blijft alleen de toelichting over.
     */
    public function activityCategory(): string
    {
        return match (true) {
            $this->delivered->photos > 0 => 'image',
            $this->delivered->videos > 0 || $this->delivered->documents > 0 => 'document',
            default => 'comment',
        };
    }

    public function activityDescription(): ?string
    {
        return $this->summary() . ' aangeleverd via de informatie-aanvraag';
    }

    public function activityMetadata(): ?array
    {
        return [
            'photos' => $this->delivered->photos,
            'videos' => $this->delivered->videos,
            'documents' => $this->delivered->documents,
            'has_note' => $this->delivered->has_note,
            'to' => $this->recipient,
            'thumbnail_path' => $this->delivered->first_photo_path,
            'thumbnail_image_id' => $this->delivered->first_photo_id,
        ];
    }

    /**
     * Wat er binnengekomen is, als Nederlandse opsomming. Niets meegestuurd kan
     * niet gebeuren — de aanvraag weigert een lege inzending — maar de zin moet
     * ook dan lopen.
     */
    public function summary(): string
    {
        $parts = array_filter([
            $this->countedNoun($this->delivered->photos, 'foto', "foto's"),
            $this->countedNoun($this->delivered->videos, 'video', "video's"),
            $this->countedNoun($this->delivered->documents, 'document', 'documenten'),
            $this->delivered->has_note ? 'een toelichting' : null,
        ]);

        if ($parts === []) {
            return 'Niets';
        }

        $parts = array_values($parts);
        $last = array_pop($parts);

        return $parts === [] ? ucfirst($last) : ucfirst(implode(', ', $parts) . ' en ' . $last);
    }

    private function countedNoun(int $count, string $singular, string $plural): ?string
    {
        return match (true) {
            $count < 1 => null,
            $count === 1 => '1 ' . $singular,
            default => $count . ' ' . $plural,
        };
    }

    private function customerLabel(): string
    {
        $name = $this->customer_name ?: 'Klant';

        return $this->recipient ? $name . ' (' . $this->recipient . ')' : $name;
    }
}
