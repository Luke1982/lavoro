<?php

namespace App\Domain\Signals\ServiceOrders;

use App\Models\ServiceJob;

/**
 * A finished keuring was opened again so it can be redone. Its completion date and
 * who finished it are wiped; this keeps both, and the outcome it had, so the
 * timeline still says what was undone.
 */
class ServiceJobReopened extends ServiceJobSignal
{
    public function __construct(
        ServiceJob $service_job,
        public ?string $previous_completed_on = null,
        public ?int $previous_completed_by = null,
    ) {
        parent::__construct($service_job);
    }

    public static function key(): string
    {
        return 'servicejob.reopened';
    }

    public static function label(): string
    {
        return 'Keuring heropend';
    }

    public static function coveredFields(): array
    {
        return ['completed_on', 'completed_by'];
    }

    public function activityDescription(): ?string
    {
        $machine = $this->service_job->asset?->historyLabel();
        $outcome = $this->service_job->outcome;

        return 'Keuring' . ($outcome ? ' met uitkomst ' . $outcome : '') . ' heropend'
            . ($machine ? ': ' . $machine : '');
    }

    public function changes(): array
    {
        return array_values(array_filter([
            self::dayChange('completed_on', 'Afgerond op', $this->previous_completed_on, null),
            self::personChange('completed_by', 'Afgerond door', $this->previous_completed_by, null),
        ]));
    }
}
