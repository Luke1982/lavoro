<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Domain\Signals\BaseSignal;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Iemand wilde de werkbon sluiten en werd tegengehouden door taken die diegene zelf
 * niet te zien krijgt: ze horen bij een rol waarin diegene deze bon niet uitvoert.
 *
 * Dat is niemands fout. Wie het probeerde ziet een bon die af lijkt, loopt vast op werk
 * dat voor hem of haar niet bestaat, en kan er zelf niets aan doen. Daarom gaat het
 * bericht naar wie de bon wél mag bijwerken: die kan de taak alsnog afronden, annuleren
 * of bij de juiste monteur neerleggen.
 *
 * De titels van de verborgen taken gaan mee en niet alleen hun aantal, want de lezer
 * moet weten wat er nog moet gebeuren zonder eerst te gaan zoeken.
 */
class ServiceOrderCloseBlockedByHiddenTasks extends BaseSignal implements ServiceOrderSignal
{
    /**
     * @param  array<int, string>  $hidden_task_titles
     * @param  array<int, string>  $role_names
     */
    public function __construct(
        public ServiceOrder $service_order,
        public array $hidden_task_titles,
        public array $role_names,
        public int $open_task_count,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'serviceorder.close_blocked_by_hidden_tasks';
    }

    public static function label(): string
    {
        return 'Sluiten geblokkeerd door verborgen taken';
    }

    public function serviceOrder(): ServiceOrder
    {
        return $this->service_order;
    }

    public function subject(): Model
    {
        return $this->service_order;
    }

    public function activityCategory(): string
    {
        return 'stage';
    }

    public function activityDescription(): ?string
    {
        return 'Sluiten geblokkeerd: ' . $this->hiddenTaskSummary() . ($this->isOneTask()
            ? ' staat open en is niet zichtbaar voor de gebruiker'
            : ' staan open en zijn niet zichtbaar voor de gebruiker');
    }

    /**
     * Het aantal openstaande taken staat erbij, zodat de metadata later nog vertelt
     * hoeveel er van de blokkade zichtbaar was en hoeveel niet.
     */
    public function activityMetadata(): ?array
    {
        return [
            'hidden_task_titles' => $this->hidden_task_titles,
            'role_names' => $this->role_names,
            'open_task_count' => $this->open_task_count,
        ];
    }

    /** Bepaalt of de zin over deze taken enkelvoud of meervoud wordt. */
    public function isOneTask(): bool
    {
        return count($this->hidden_task_titles) === 1;
    }

    /**
     * Hooguit drie namen voluit, daarna een telling. Een bericht dat over twintig
     * taken alle twintig titels uitschrijft wordt niet meer gelezen.
     */
    public function hiddenTaskSummary(): string
    {
        $named = array_slice($this->hidden_task_titles, 0, 3);
        $rest = count($this->hidden_task_titles) - count($named);

        return implode(', ', $named) . ($rest > 0 ? ' en nog ' . $rest . ' andere' : '');
    }
}
