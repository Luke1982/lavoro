<?php

namespace App\Services;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Wie welke werkbontaak te zien krijgt, op een werkbon die al in handen is.
 *
 * De regel zelf staat in ServiceOrderTaskInstance::scopeSeenBy, zodat het scherm,
 * het weigeren van het sluiten en elke vraag aan de database dezelfde regel stellen.
 * Zouden ze uit elkaar lopen, dan blokkeert een taak het sluiten die de lezer nergens
 * terugvindt zonder dat iets dat uitlegt.
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
     * gesteld. Ze delen daarom dit oordeel, en de database wordt er één keer per vraag
     * naar gevraagd in plaats van één keer per taak.
     *
     * @return \Closure(ServiceOrderTaskInstance): bool
     */
    private function sees(ServiceOrder $service_order, User $user): \Closure
    {
        if (ServiceOrderTaskInstance::seesEveryTask($user)) {
            return fn () => true;
        }

        $seen = $service_order->taskInstances()->seenBy($user)->pluck('id')->flip();

        return fn (ServiceOrderTaskInstance $instance) => $seen->has($instance->getKey());
    }
}
