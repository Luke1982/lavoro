<?php

namespace App\Domain\Signals\Announcements;

use App\Domain\Signals\BaseSignal;
use App\Models\InternalAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Iemand heeft op Begrepen gedrukt.
 *
 * Aanmaken, wijzigen en verwijderen legt RecordsHistory vanzelf vast, maar dit
 * is een schrijfactie op een pivot en die ziet Eloquent niet als verandering van
 * het record. Zonder dit signaal zou het enige wat een aankondiging bestaansrecht
 * geeft — dat mensen hem gelezen hebben — buiten de tijdlijn vallen.
 */
class AnnouncementAcknowledged extends BaseSignal
{
    public function __construct(
        public InternalAnnouncement $announcement,
        public User $recipient,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'announcement.acknowledged';
    }

    public static function label(): string
    {
        return 'Aankondiging bevestigd';
    }

    public function subject(): Model
    {
        return $this->announcement;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    public function activityDescription(): ?string
    {
        return $this->recipient->name . ' heeft de aankondiging bevestigd';
    }

    public function activityMetadata(): ?array
    {
        return ['recipient_id' => $this->recipient->getKey()];
    }
}
