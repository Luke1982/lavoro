<?php

namespace App\Domain\ServiceOrders;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;

/**
 * Moves a selection of werkbonnen to one fase, each by the same rules as the
 * stepper on the werkbon itself: whether this person may choose that fase for it,
 * and whether it meets what a closing fase asks. A werkbon that does not stays
 * where it is; the others move.
 */
class BulkStageMove
{
    public function __construct(private readonly ClosingRequirements $requirements) {}

    /**
     * @param  iterable<ServiceOrder>  $service_orders
     * @return array<string, string> Why each werkbon that stayed did, keyed by its number.
     */
    public function move(User $user, iterable $service_orders, ServiceOrderStage $stage): array
    {
        $stayed = [];

        foreach ($service_orders as $service_order) {
            $reason = $this->reasonToStay($user, $service_order, $stage);

            if ($reason !== null) {
                $stayed[$service_order->number] = $reason;

                continue;
            }

            $service_order->moveToStage($stage);
        }

        return $stayed;
    }

    /**
     * What the person who moved them reads: how many stayed and why, per werkbon.
     *
     * @param  array<string, string>  $stayed
     */
    public static function summary(array $stayed): string
    {
        $listed = collect($stayed)->map(fn (string $reason, string $number) => $number . ' (' . $reason . ')')->join(', ');

        return (count($stayed) === 1 ? '1 werkbon is blijven staan: ' : count($stayed) . ' werkbonnen zijn blijven staan: ')
            . $listed . '.';
    }

    private function reasonToStay(User $user, ServiceOrder $service_order, ServiceOrderStage $stage): ?string
    {
        if (!$user->can('updateStage', [$service_order, $stage])) {
            return $service_order->is_closed ? 'gesloten, en heropenen mag jij niet' : 'deze fase mag jij niet kiezen';
        }

        $unmet = $this->requirements->unmetFor($service_order, $stage);

        return $unmet ? collect($unmet)->pluck('short')->join(', ') : null;
    }
}
