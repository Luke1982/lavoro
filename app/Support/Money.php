<?php

namespace App\Support;

/**
 * Bedragen worden overal in centen bewaard en op twee manieren getoond. Die
 * twee zien er bijna hetzelfde uit en zijn dat niet:
 *
 * - voor mensen: 1.234,50 -- punt als duizendtal, komma als decimaal
 * - voor machines: 1234.50 -- geen duizendtal, punt als decimaal, want dat
 *   is wat de UBL-factuur en het SEPA-incassobestand voorschrijven
 *
 * Ze stonden allebei als losse number_format-aanroep door de code heen. Wie
 * de ene "verbeterde" naar de andere maakte stilletjes een bestand dat de bank
 * weigert. Vandaar twee methodes met een naam.
 *
 * Number::currency() van Laravel doet dit ook, maar vereist de PHP-uitbreiding
 * intl. Die staat niet op de server en is dit niet waard.
 */
final class Money
{
    /** Zoals het op het scherm en op de factuur hoort te staan. */
    public static function human(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    /** Zoals een bank of boekhoudpakket het inleest. */
    public static function machine(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
