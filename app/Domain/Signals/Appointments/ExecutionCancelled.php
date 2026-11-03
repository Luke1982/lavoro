<?php

namespace App\Domain\Signals\Appointments;

/**
 * Een monteur heeft zijn eigen deel van een afspraak geannuleerd. De afspraak zelf
 * blijft bestaan, en de andere monteurs erop houden hun deel; daarom is dit iets
 * anders dan AppointmentCancelled.
 *
 * Zijn tijden kunnen daarna niet meer ingevuld worden totdat iemand met het recht
 * om tijden vrij te geven de annulering intrekt.
 */
class ExecutionCancelled extends ExecutionSignal
{
    public static function key(): string
    {
        return 'appointment.execution_cancelled';
    }

    public static function label(): string
    {
        return 'Deelname geannuleerd';
    }

    public function activityDescription(): ?string
    {
        return 'Deelname van ' . $this->executor->name . ' geannuleerd';
    }
}
