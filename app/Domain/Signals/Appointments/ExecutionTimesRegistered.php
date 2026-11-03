<?php

namespace App\Domain\Signals\Appointments;

/**
 * De tijden van één monteur op een afspraak zijn vastgelegd, door hemzelf of door
 * iemand die tijden voor een ander mag invullen. Wie het deed staat als actor op
 * de regel; de zin noemt daarom alleen wiens tijden het zijn.
 */
class ExecutionTimesRegistered extends ExecutionSignal
{
    public static function key(): string
    {
        return 'appointment.execution_times_registered';
    }

    public static function label(): string
    {
        return 'Tijden geregistreerd';
    }

    public function activityDescription(): ?string
    {
        return 'Tijden van ' . $this->executor->name . ' geregistreerd: ' . $this->timesSentence($this->after);
    }
}
