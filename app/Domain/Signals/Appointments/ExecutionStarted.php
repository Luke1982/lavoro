<?php

namespace App\Domain\Signals\Appointments;

/**
 * Een monteur meldde dat hij aan zijn deel van de afspraak begonnen is.
 */
class ExecutionStarted extends ExecutionSignal
{
    public static function key(): string
    {
        return 'appointment.execution_started';
    }

    public static function label(): string
    {
        return 'Uitvoering gestart';
    }

    public function activityDescription(): ?string
    {
        return $this->executor->name . ' is begonnen om ' . ($this->clockTime($this->after['actual_start']) ?? '-');
    }
}
