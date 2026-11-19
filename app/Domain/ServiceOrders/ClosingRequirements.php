<?php

namespace App\Domain\ServiceOrders;

use App\Models\GeneralSetting;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a werkbon must have before it closes: every task finished or cancelled,
 * enough photos for the customer, and the customer's signature.
 *
 * The phase stepper and the bulk move ask the same question here. Asked in two
 * places, it would only take one forgotten rule for a bulk move to close a
 * werkbon the stepper would have refused.
 */
class ClosingRequirements
{
    /**
     * Every fase that closes the werkbon asks for them, the invoiced one too:
     * otherwise a jump straight to invoicing would close it with open tasks. A
     * werkbon that is closed already passed them when it closed. Each one comes as
     * the sentence for the werkbon itself and the few words for a list of them.
     *
     * @return list<array{field: string, message: string, short: string}>
     */
    public function unmetFor(ServiceOrder $service_order, ServiceOrderStage $stage): array
    {
        if (!$stage->closesOrder() || $service_order->is_closed) {
            return [];
        }

        $unmet = [];
        $open = $this->openTasks($service_order)->count();

        if ($open > 0) {
            $unmet[] = $open === 1
                ? ['field' => 'open_task_instances', 'short' => 'nog 1 open taak',
                    'message' => 'Er is nog 1 taak niet afgerond of geannuleerd. Rond hem af of annuleer hem '
                        . 'voordat je de werkbon sluit.']
                : ['field' => 'open_task_instances', 'short' => 'nog ' . $open . ' open taken',
                    'message' => 'Er zijn nog ' . $open . ' taken niet afgerond of geannuleerd. Rond ze '
                        . 'af of annuleer ze voordat je de werkbon sluit.'];
        }

        $minimum = (int) GeneralSetting::get('serviceorder_min_images', 0);
        $photos = $minimum > 0 ? $service_order->images()->count() : 0;

        if ($photos < $minimum) {
            $unmet[] = ['field' => 'service_order_stage_id', 'short' => "te weinig foto's",
                'message' => 'Er zijn minimaal ' . $minimum . " foto's vereist om de werkbon te sluiten."
                    . ' Er zijn er ' . $photos . ' toegevoegd.'];
        }

        if (blank($service_order->signed_by) || blank($service_order->signature_base64)) {
            $unmet[] = ['field' => 'service_order_stage_id', 'short' => 'niet ondertekend',
                'message' => 'De werkbon moet ondertekend zijn door de klant voordat deze gesloten kan worden.'];
        }

        return $unmet;
    }

    /** @return Collection<int, ServiceOrderTaskInstance> */
    public function openTasks(ServiceOrder $service_order): Collection
    {
        $service_order->loadMissing(['taskInstances.userRoles', 'taskInstances.serviceOrderTask', 'events']);

        return $service_order->taskInstances
            ->where('is_complete', false)
            ->where('is_cancelled', false)
            ->values();
    }
}
