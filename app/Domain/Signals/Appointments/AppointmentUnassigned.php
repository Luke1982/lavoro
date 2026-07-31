<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Mensen zijn van een afspraak gehaald. Draagt alleen wie eraf is, en met opzet
 * niet wie ervoor in de plaats komt: wie eraf gehaald wordt hoort dat het niet
 * meer zijn werk is, en verder niets.
 *
 * Implementeert AppointmentSignal niet. Dat is de verzameling feiten waarop de
 * standaardmails en de Google-koppeling meeluisteren, en een bezetting die
 * wijzigt is voor beide geen aanleiding om de klant te mailen of de agenda
 * opnieuw te schrijven.
 */
class AppointmentUnassigned extends BaseSignal
{
    /**
     * De begintijd is die van vóór deze bewerking en niet die van de afspraak nu.
     * Wie in één handeling verzet én vervangen wordt, moet de afspraak herkennen
     * aan het moment dat hij kende, en niet horen wanneer hij nu plaatsvindt.
     *
     * @param  array<int, int>  $removed_user_ids
     */
    public function __construct(
        public Event $event,
        public array $removed_user_ids,
        public ?Carbon $started_at = null,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'appointment.unassigned';
    }

    public static function label(): string
    {
        return 'Monteur van afspraak gehaald';
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}
