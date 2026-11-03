<?php

namespace App\Domain\Signals\Appointments;

use App\Enums\EventCompletionStatus;

/**
 * Het deel van één monteur staat weer open: geregistreerde tijden zijn gewist, of
 * een annulering is ingetrokken, zodat de tijden opnieuw ingevuld kunnen worden.
 * De tijden van vóór het vrijgeven blijven in changes() bewaard.
 */
class ExecutionTimesReleased extends ExecutionSignal
{
    public static function key(): string
    {
        return 'appointment.execution_times_released';
    }

    public static function label(): string
    {
        return 'Tijden vrijgegeven';
    }

    public function activityDescription(): ?string
    {
        if ($this->before['completion_status'] === EventCompletionStatus::cancelled->value) {
            return 'Annulering van ' . $this->executor->name . ' ingetrokken, de tijden kunnen weer ingevuld worden';
        }

        return 'Tijden van ' . $this->executor->name . ' vrijgegeven (waren ' . $this->timesSentence($this->before) . ')';
    }
}
