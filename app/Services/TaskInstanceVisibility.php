<?php

namespace App\Services;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Wie welke werkbontaak te zien krijgt.
 *
 * Een taak zonder rollen is van iedereen. Een taak met rollen is van de mensen die
 * de werkbon in een van die rollen uitvoeren, en die rollen staan bij de afspraak,
 * niet bij de gebruiker: dezelfde monteur kan de ene bon als elektricien draaien en
 * de andere als installateur.
 *
 * De regel staat hier en niet in de controller, omdat het scherm en het weigeren van
 * het sluiten dezelfde vraag stellen. Zouden ze uit elkaar lopen, dan blokkeert een
 * taak het sluiten die de lezer nergens terugvindt zonder dat iets dat uitlegt.
 */
class TaskInstanceVisibility
{
    /** @return Collection<int, ServiceOrderTaskInstance> */
    public function visibleTo(ServiceOrder $service_order, User $user): Collection
    {
        return $service_order->taskInstances
            ->filter($this->sees($service_order, $user))
            ->values();
    }

    /** @return Collection<int, ServiceOrderTaskInstance> */
    public function hiddenFrom(ServiceOrder $service_order, User $user): Collection
    {
        return $service_order->taskInstances
            ->reject($this->sees($service_order, $user))
            ->values();
    }

    /**
     * Beide vragen zijn dezelfde vraag, één keer bevestigend en één keer ontkennend
     * gesteld. Ze delen daarom dit oordeel, en de rollen worden er één keer per vraag
     * bij gezocht in plaats van één keer per taak: dat kost een query per afspraak.
     *
     * @return \Closure(ServiceOrderTaskInstance): bool
     */
    private function sees(ServiceOrder $service_order, User $user): \Closure
    {
        if ($user->isAdmin() || $user->hasPermission('serviceorder.see_all_task_instances')) {
            return fn () => true;
        }

        $role_ids = $service_order->events
            ->flatMap(fn ($event) => $event->executingUserRoleIds($user->id))
            ->unique()
            ->all();

        return fn (ServiceOrderTaskInstance $instance) => $instance->userRoles->isEmpty()
            || $instance->userRoles->pluck('id')->intersect($role_ids)->isNotEmpty();
    }
}
