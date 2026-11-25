<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Evals\Scenarios\SignedQuoteScenario;
use App\Domain\Planning\Clock;
use Carbon\CarbonImmutable;

/**
 * Signed offertes to be turned into a klus: one real file kept out of git, and
 * made-up ones printed when they are first needed. Years and dates in them are
 * worked out from today, so an offerte is never years old on the day it runs.
 */
final class QuoteScenarios
{
    /**
     * Contact details on the made-up offertes are plainly made up: example.nl
     * addresses, numbers of zeros and streets that exist nowhere, so nothing in
     * them can be mistaken for, or end up mailing, a real business. Hotel
     * Rivierzicht is the demo's own customer and keeps the address it has there.
     */
    private const SUPPLIER = 'Voorbeeld Klimaattechniek B.V. — Verzonnenweg 4, 0000 AA Voorbeeldstad';

    /** @return array<int, EvalScenario> */
    public static function all(): array
    {
        $today = CarbonImmutable::parse(Clock::today());
        $year = $today->year;
        $next_spring = $today->month <= 3 ? $year : $year + 1;
        $dated = fn (int $days_ago) => $today->subDays($days_ago)->format('d-m-Y');

        return [
            /**
             * A real signed offerte. It names a real customer, so the file and
             * everything the scenario knows about it — persona, customer, offerte
             * number, products, contract — stay out of git, in the JSON beside it.
             */
            new SignedQuoteScenario(['name' => 'echt', 'fixture' => QuoteFixture::directory() . '/echte-offerte.json']),

            /** An existing customer, a second address, products that are all in the catalogue, nothing to keep. */
            new SignedQuoteScenario([
                'name' => 'hotel-multisplit',
                'quote' => [
                    'supplier' => self::SUPPLIER,
                    'number' => 'OF-' . $year . '-0418',
                    'date' => $dated(9),
                    'customer' => ['Hotel Rivierzicht', 't.a.v. de heer De Wit', 'Waalkade 3', '4001 LE Tiel'],
                    'lines' => [
                        ['Leveren en monteren Daikin 3MXM52A buitendeel multi split', 1, 2450],
                        ['Leveren en monteren Daikin Perfera CTXM15R binnendeel wandmodel', 3, 610],
                        ['Montagemateriaal, koelleiding tot 5 m per binnendeel en condensafvoer', 1, 385],
                        ['Optie: wifi-module per binnendeel', 0, 89],
                        ['Voorrijkosten', 1, 0],
                    ],
                    'notes' => [
                        'Werkadres: Personeelsverblijf Hotel Rivierzicht, Voorbeeldstraat 8, 0000 AC Tiel.',
                        'Levertijd: 4 - 6 weken na opdracht.',
                        'Betaling binnen 14 dagen na oplevering.',
                    ],
                    'signed_by' => 'R. de Wit',
                ],
                'opening' => 'Getekend retour van Hotel Rivierzicht. Kun je dit klaarzetten?',
                'persona' => implode("\n", [
                    'Je hebt de getekende offerte OF-' . $year . '-0418 van Hotel Rivierzicht in de assistent gezet en wilt dat de klus wordt opgezet en ingepland.',
                    'Hotel Rivierzicht in Tiel staat al in het systeem: gebruik die klant, maak geen nieuwe aan.',
                    'Het werk gebeurt niet bij het hotel zelf maar in het personeelsverblijf, Voorbeeldstraat 8, 0000 AC Tiel: daar moet een locatie voor komen.',
                    'Het is 8 uur werk, door één monteur, op één dag.',
                    'Bij het inplannen kies je de eerste voorgestelde optie.',
                    'Er hoort geen onderhoudscontract bij. De offerte hoeft niet bewaard te worden.',
                    'Als alles is aangemaakt en ingepland, ben je klaar.',
                ]),
                'quote_number' => 'OF-' . $year . '-0418',
                'customer' => ['name' => 'Hotel Rivierzicht', 'address' => 'Waalkade 3', 'postal_code' => '4001', 'city' => 'Tiel', 'existing' => true],
                'products' => [
                    ['match' => ['Daikin', '3MXM52A'], 'quantity' => 1],
                    ['match' => ['Daikin', 'CTXM15R'], 'quantity' => 3],
                ],
                'type' => 'installation',
                'work_minutes' => 480,
                'earliest_days' => 28,
                'contract' => null,
                'keep_document' => false,
                'other_location' => true,
                'work_address' => ['address' => 'Voorbeeldstraat 8', 'city' => 'Tiel'],
            ]),

            /** A new business customer, a heat pump over two days, and a contract twice a year. */
            new SignedQuoteScenario([
                'name' => 'bakkerij-warmtepomp',
                'quote' => [
                    'supplier' => self::SUPPLIER,
                    'number' => 'OF-' . $year . '-0431',
                    'date' => $dated(7),
                    'customer' => ['Bakkerij Verhoeven', 't.a.v. mevrouw Verhoeven', 'Proefstraat 21', '0000 AB Proefdorp', 'tel. 0000 000 000', 'bakkerij@example.nl'],
                    'lines' => [
                        ['Leveren en plaatsen Vaillant aroTHERM plus VWL 75/6 A monoblock warmtepomp', 1, 9850],
                        ['Leveren en plaatsen Vaillant allSTOR VPS 300/3-5 buffervat', 1, 1640],
                        ['Aansluiten op bestaande cv-installatie, inclusief leidingwerk en elektra', 1, 2200],
                        ['Optie: 6 zonnepanelen LONGi Hi-MO 6 430 Wp', 0, 2850],
                        ['Onderhoudscontract warmtepomp: 2x per jaar onderhoud, eerste beurt voorjaar ' . $next_spring . ",\nprijs per jaar € 290,00 excl. btw", 1, 0],
                    ],
                    'notes' => [
                        'Installatie 3 - 4 weken na opdrachtbevestiging.',
                        'De bestaande cv-ketel blijft als bijverwarming in gebruik.',
                    ],
                    'signed_by' => 'A. Verhoeven',
                ],
                'opening' => 'Opdracht binnen, graag inplannen',
                'persona' => implode("\n", [
                    'Je hebt de getekende offerte OF-' . $year . '-0431 van Bakkerij Verhoeven in de assistent gezet en wilt dat de klus wordt opgezet en ingepland.',
                    'Bakkerij Verhoeven is een nieuwe klant: laat hem aanmaken met de gegevens van de offerte.',
                    'Het werk gebeurt op het adres van de klant.',
                    'Het is 16 uur werk voor één monteur, verdeeld over twee dagen van 8 uur.',
                    'Bij het inplannen kies je de eerste voorgestelde optie.',
                    'Ja, het onderhoudscontract moet aangemaakt worden. Ingangsdatum: 1 april ' . $next_spring . '.',
                    'Ja, de offerte moet bij de werkbon bewaard worden.',
                    'Als alles is aangemaakt en ingepland, ben je klaar.',
                ]),
                'quote_number' => 'OF-' . $year . '-0431',
                'customer' => ['name' => 'Verhoeven', 'address' => 'Proefstraat 21', 'postal_code' => '0000 AB', 'city' => 'Proefdorp'],
                'products' => [
                    ['match' => ['Vaillant', 'VWL 75/6'], 'quantity' => 1],
                    ['match' => ['Vaillant', 'VPS 300'], 'quantity' => 1],
                ],
                'type' => 'installation',
                'work_minutes' => 960,
                'earliest_days' => 21,
                'contract' => ['frequency' => 'Halfjaarlijks', 'start_year' => $next_spring],
                'keep_document' => true,
                'other_location' => false,
            ]),

            /** A brand the catalogue has never seen, so the products have to be made under the right types. */
            new SignedQuoteScenario([
                'name' => 'gree-nieuw-merk',
                'quote' => [
                    'supplier' => self::SUPPLIER,
                    'number' => 'OF-' . $year . '-0440',
                    'date' => $dated(5),
                    'customer' => ['M. de Graaf', 'Testdijk 7', '0000 AD Voorbeelddorp', 'tel. 06 00000000'],
                    'lines' => [
                        ['Gree Fairy binnendeel wandmodel GWH12ACC-K6DNA1F/I, 3,5 kW', 1, 640],
                        ['Gree Fairy buitendeel GWH12ACC-K6DNA1F/O', 1, 1050],
                        ['Montage inclusief 4 m leiding en witte goot', 1, 295],
                    ],
                    'notes' => ['Installatie binnen 2 weken na akkoord.'],
                    'signed_by' => 'M. de Graaf',
                ],
                'opening' => 'getekend',
                'persona' => implode("\n", [
                    'Je hebt de getekende offerte OF-' . $year . '-0440 van M. de Graaf in de assistent gezet en wilt dat de klus wordt opgezet en ingepland.',
                    'M. de Graaf is een nieuwe klant: laat hem aanmaken met de gegevens van de offerte.',
                    'Het werk gebeurt op het adres van de klant.',
                    'Producten die nog niet in het assortiment staan, mogen aangemaakt worden.',
                    'Het is 6 uur werk, door één monteur, op één dag.',
                    'Bij het inplannen kies je de eerste voorgestelde optie.',
                    'Er hoort geen onderhoudscontract bij. Ja, de offerte moet bij de werkbon bewaard worden.',
                    'Als alles is aangemaakt en ingepland, ben je klaar.',
                ]),
                'quote_number' => 'OF-' . $year . '-0440',
                'customer' => ['name' => 'Graaf', 'address' => 'Testdijk 7', 'postal_code' => '0000 AD', 'city' => 'Voorbeelddorp'],
                'products' => [
                    ['match' => ['Gree', 'K6DNA1F/I'], 'quantity' => 1],
                    ['match' => ['Gree', 'K6DNA1F/O'], 'quantity' => 1],
                ],
                'type' => 'installation',
                'work_minutes' => 360,
                'earliest_days' => 0,
                'contract' => null,
                'keep_document' => true,
                'other_location' => false,
            ]),
        ];
    }
}
