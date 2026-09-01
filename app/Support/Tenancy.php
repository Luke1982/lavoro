<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Iets uitvoeren binnen de database van één klant.
 *
 * Bestaat omdat initialize() en end() met de hand koppelen fout gaat zodra er
 * iets tussenin gooit: dan blijft de tenant openstaan en draait de volgende
 * ronde -- of de rest van het verzoek -- in de database van de vorige klant.
 * Dat levert geen foutmelding op, alleen de verkeerde gegevens.
 *
 * En niet tenancy()->runForMultiple(): dat zet de vorige tenant alleen terug
 * als er niets misgaat -- het herstel staat na de lus en niet in een finally --
 * en het geeft niets terug. Precies de twee dingen waar dit voor is.
 */
final class Tenancy
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function within(Tenant $tenant, callable $work): mixed
    {
        $previous = tenancy()->initialized ? tenancy()->tenant : null;

        tenancy()->initialize($tenant);

        try {
            return $work();
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
        }
    }
}
