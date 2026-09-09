<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            $result = $work();

            /**
             * Job::dispatch() zet niets in de wachtrij: het geeft een
             * PendingDispatch terug die dat pas in zijn destructor doet. Een
             * pijlfunctie geeft die waarde door aan deze functie, en dan valt
             * het object hierbuiten uit elkaar -- nadat tenancy hieronder is
             * beëindigd. De job kwam zo zonder klant in de wachtrij en draaide
             * bij de worker tegen de centrale database aan.
             *
             * Dat gaf geen fout bij het plannen, alleen later: 'Base table
             * lavoro_landlord.google_synced_calendars doesn't exist', elke vijf
             * minuten opnieuw. Op null zetten laat php het object hier
             * opruimen, met de klant nog open.
             */
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } finally {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();
        }
    }

    /** Is de database van deze klant te openen? */
    public static function reachable(Tenant $tenant): bool
    {
        try {
            return (bool) self::within($tenant, fn () => DB::connection('tenant')->getPdo());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Hetzelfde doen voor elke klant die te bereiken is.
     *
     * Een klant met een verdwenen database laat elke taak die over hem gaat
     * omvallen. Voor werk dat elke vijf minuten draait zijn dat honderden
     * mislukte taken per dag, en daar verdwijnt alles echts tussen: op
     * productie stonden er 1313, allemaal van dezelfde kapotte klant.
     *
     * Overslaan en niet stilhouden: het staat in het logboek, en de doctor
     * meldt zo'n klant apart.
     */
    public static function forEachReachable(callable $work): void
    {
        Tenant::on('central')->cursor()->each(function (Tenant $tenant) use ($work) {
            if (!static::reachable($tenant)) {
                Log::warning('Klant overgeslagen: database niet te openen.', [
                    'tenant' => $tenant->id,
                    'name' => $tenant->name,
                ]);

                return;
            }

            static::within($tenant, $work);
        });
    }
}
