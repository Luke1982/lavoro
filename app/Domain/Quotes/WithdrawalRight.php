<?php

namespace App\Domain\Quotes;

use App\Models\Quote;

/**
 * What a private customer has to be told about the 14 days they get to change
 * their mind: the standard instructions and the model form that the law appends
 * to article 6:230m BW, filled in with the company's own details. A consumer who
 * signs at home or through an emailed link has this right; told nothing, they
 * keep it for a year longer.
 *
 * The texts follow the model wording for a service. They are frozen into each
 * version, so what a customer was told stays what they were told.
 */
final class WithdrawalRight
{
    /**
     * @param  array<string, ?string>  $company  as Company::details() gives it
     * @return array{days: int, instructions: array<int, string>, form: array<int, string>}
     */
    public static function for(array $company): array
    {
        $trader = self::trader($company);

        return [
            'days' => Quote::COOLING_OFF_DAYS,
            'instructions' => [
                'U hebt het recht om binnen ' . Quote::COOLING_OFF_DAYS . ' dagen zonder opgave van redenen '
                    . 'de overeenkomst te herroepen. De herroepingstermijn verstrijkt ' . Quote::COOLING_OFF_DAYS
                    . ' dagen na de dag waarop de overeenkomst is gesloten.',
                'Om het herroepingsrecht uit te oefenen, moet u ons (' . $trader . ') via een ondubbelzinnige '
                    . 'verklaring (bijvoorbeeld schriftelijk per post of per e-mail) op de hoogte stellen van uw '
                    . 'beslissing de overeenkomst te herroepen. U kunt hiervoor gebruikmaken van het modelformulier '
                    . 'voor herroeping, maar bent hiertoe niet verplicht. Om de termijn na te leven volstaat het om '
                    . 'uw mededeling te verzenden voordat de herroepingstermijn is verstreken.',
                'Als u de overeenkomst herroept, ontvangt u alle betalingen die u tot op dat moment hebt gedaan '
                    . 'onverwijld en in ieder geval niet later dan 14 dagen nadat wij op de hoogte zijn gesteld van '
                    . 'uw beslissing van ons terug. Wij betalen u terug met hetzelfde betaalmiddel als waarmee u de '
                    . 'oorspronkelijke betaling hebt verricht, zonder kosten voor u.',
                'Als u hebt gevraagd om de werkzaamheden tijdens de herroepingstermijn te laten beginnen, betaalt '
                    . 'u ons een bedrag dat evenredig is aan wat al is uitgevoerd op het moment dat u ons laat weten '
                    . 'dat u de overeenkomst herroept, vergeleken met de volledige uitvoering van de overeenkomst.',
            ],
            'form' => [
                'Aan: ' . $trader,
                'Ik/Wij* deel/delen* u hierbij mede dat ik/wij* onze overeenkomst betreffende de verrichting van de '
                    . 'volgende dienst herroep/herroepen*:',
                'Gesloten op:',
                'Naam/Namen consument(en):',
                'Adres consument(en):',
                'Handtekening van consument(en) (alleen wanneer dit formulier op papier wordt ingediend):',
                'Datum:',
                '* Doorhalen wat niet van toepassing is.',
            ],
        ];
    }

    /** @param array<string, ?string> $company */
    private static function trader(array $company): string
    {
        return implode(', ', array_filter([
            $company['name'] ?? null,
            $company['address_line1'] ?? null,
            trim(($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? '')) ?: null,
            $company['phone'] ?? null,
            $company['email'] ?? null,
        ])) ?: 'de ondernemer';
    }
}
