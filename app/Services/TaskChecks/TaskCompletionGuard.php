<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;
use App\Services\TaskInstanceSerialSlotService;

/**
 * The one answer to "may this task be completed now", so every way of
 * completing a task holds to the same rule: every machine it delivers is
 * registered, and every required check on it is ticked.
 */
class TaskCompletionGuard
{
    public function __construct(private readonly TaskInstanceSerialSlotService $slots) {}

    public function problem(ServiceOrderTaskInstance $instance): ?string
    {
        if ($instance->product_id) {
            $instance->loadMissing([
                'product.brand',
                'product.productables.childProduct.brand',
                'productables',
                'assets',
            ]);

            if (!$this->slots->allSlotsFilled($instance)) {
                return 'Registreer eerst alle machines voordat je deze taak voltooit.';
            }
        }

        $open_required = $instance->checks()
            ->where('is_required', true)
            ->where('is_done', false)
            ->exists();

        return $open_required ? 'Vink eerst alle verplichte checks af.' : null;
    }
}
