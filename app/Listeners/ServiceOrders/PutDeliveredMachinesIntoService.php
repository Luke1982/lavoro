<?php

namespace App\Listeners\ServiceOrders;

use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Domain\Signals\Tasks\TaskCompletionChanged;
use App\Models\Asset;

/**
 * The machines a taak delivers go into service when the taak is done.
 *
 * Registered ahead from a signed offerte, a machine is sold but not hung, so
 * it has no date in service and no date its maintenance comes due — and left
 * at that it would never turn up as due at all. Completing the taak is the
 * moment it was hung. Machines registered from the drawer have their dates
 * already and are left alone.
 */
class PutDeliveredMachinesIntoService
{
    public function handle(TaskCompletionChanged $signal): void
    {
        if (!$signal->is_complete) {
            return;
        }

        $signal->task_instance->assets()
            ->whereNull('date_in_service')
            ->whereNotNull('product_id')
            ->with('product.productType')
            ->get()
            ->each(fn (Asset $asset) => $asset->update(RegisterTaskAssetsAction::serviceDatesFor($asset->product)));
    }
}
