<?php

namespace App\Services\TaskChecks;

use App\Models\ServiceOrderTaskInstance;
use App\Models\ServiceOrderTaskInstanceCheck;
use Illuminate\Support\Collection;

/**
 * How a task's checks read to a person: "Machine 2 (DK-2231-A)" for the machine
 * a check belongs to, and the list of what was done for the werkbon PDF.
 */
class TaskCheckLabels
{
    /** "Machine 2 (DK-2231-A)", or null for a check done once for the whole task. */
    public function machine(ServiceOrderTaskInstance $instance, ServiceOrderTaskInstanceCheck $check): ?string
    {
        return $this->machines($instance)[$check->unit_index] ?? null;
    }

    /**
     * The ticked checks with the time they were ticked, per machine or for the whole
     * task. Checks nobody ticked are left out.
     *
     * @return array<int, array{label: ?string, items: array<int, string>}>
     */
    public function done(ServiceOrderTaskInstance $instance): array
    {
        $machines = $this->machines($instance);

        return $instance->checks
            ->where('is_done', true)
            ->groupBy(fn (ServiceOrderTaskInstanceCheck $check) => $check->unit_index ?? 'all')
            ->map(fn (Collection $checks, $unit) => [
                'label' => $machines[$unit] ?? null,
                'items' => $checks->map(fn (ServiceOrderTaskInstanceCheck $check) => trim($check->title . ' '
                    . $check->checked_at?->copy()->setTimezone(config('app.display_timezone'))->format('H:i')))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Each unit's label by its place among the task's units, with the serial of the
     * machine its checks are tied to.
     *
     * @return array<int, string>
     */
    private function machines(ServiceOrderTaskInstance $instance): array
    {
        $instance->loadMissing('checks.asset');

        return $instance->checks
            ->whereNotNull('unit_index')
            ->groupBy('unit_index')
            ->sortKeys()
            ->values()
            ->mapWithKeys(function (Collection $checks, int $position) {
                $serial = $checks->first(fn (ServiceOrderTaskInstanceCheck $check) => $check->asset)?->asset->serial_number;

                return [$checks->first()->unit_index => 'Machine ' . ($position + 1) . ($serial ? ' (' . $serial . ')' : '')];
            })
            ->all();
    }
}
