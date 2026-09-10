<?php

/**
 * What a mechanic fills in per kind of installation, grouped the way a
 * maintenance report is read: cleaning, measurements, safety, function.
 *
 * Every entry is [group, question, type, details]. For measurements the details
 * hold the range a healthy installation sits in, so the filled-in reports on
 * finished work show values a service engineer would recognise.
 */
return [
    'groups' => ['Reiniging', 'Metingen', 'Veiligheid & F-gassen', 'Functie', 'Afronding'],

    'lists' => [
        'airco_indoor' => [
            ['Reiniging', 'Filters gereinigd', 'boolean'],
            ['Reiniging', 'Verdamper en ventilatorrol gereinigd', 'boolean'],
            ['Reiniging', 'Condensafvoer vrij', 'boolean'],
            ['Metingen', 'Uitblaastemperatuur koelen (°C)', 'number', ['range' => [9, 14]]],
            ['Functie', 'Werking bediening', 'radio', ['options' => ['Goed', 'Afwijkend']]],
            ['Functie', 'Staat behuizing en lamellen', 'radio', ['options' => ['Goed', 'Beschadigd']]],
            ['Afronding', 'Opmerkingen', 'text'],
        ],
        'airco_outdoor' => [
            ['Reiniging', 'Condensor gereinigd', 'boolean'],
            ['Veiligheid & F-gassen', 'Lekdichtheidscontrole F-gassen', 'radio', ['options' => ['Lekdicht', 'Lekkage gevonden', 'Niet uitgevoerd']]],
            ['Veiligheid & F-gassen', 'Koudemiddelinhoud (kg)', 'number', ['range' => [0.8, 3.4]]],
            ['Metingen', 'Zuigdruk (bar)', 'number', ['range' => [8.2, 10.8]]],
            ['Metingen', 'Persdruk (bar)', 'number', ['range' => [22, 28]]],
            ['Metingen', 'Stroomopname compressor (A)', 'number', ['range' => [3.2, 7.8]]],
            ['Functie', 'Trillingsdempers en bevestiging', 'radio', ['options' => ['Goed', 'Vervangen', 'Aandacht nodig']]],
        ],
        'heatpump' => [
            ['Reiniging', 'Vuilvanger gereinigd', 'boolean'],
            ['Metingen', 'Waterdruk installatie (bar)', 'number', ['range' => [1.5, 2.0]]],
            ['Metingen', 'Aanvoertemperatuur (°C)', 'number', ['range' => [35, 45]]],
            ['Metingen', 'Voordruk expansievat (bar)', 'number', ['range' => [0.8, 1.2]]],
            ['Veiligheid & F-gassen', 'Lekdichtheidscontrole', 'radio', ['options' => ['Lekdicht', 'Lekkage gevonden', 'Niet uitgevoerd']]],
            ['Functie', 'Legionellaprogramma actief', 'boolean'],
            ['Afronding', 'Opmerkingen', 'text'],
        ],
        'boiler' => [
            ['Reiniging', 'Sifon gereinigd', 'boolean'],
            ['Metingen', 'Rookgasanalyse CO₂ (%)', 'number', ['range' => [8.8, 9.4]]],
            ['Metingen', 'CO-waarde (ppm)', 'number', ['range' => [12, 60]]],
            ['Metingen', 'Waterdruk (bar)', 'number', ['range' => [1.4, 2.0]]],
            ['Veiligheid & F-gassen', 'Ontsteek- en ionisatiepen', 'radio', ['options' => ['Goed', 'Gereinigd', 'Vervangen']]],
            ['Veiligheid & F-gassen', 'Branderinspectie', 'radio', ['options' => ['Goed', 'Gereinigd', 'Afwijkend']]],
            ['Afronding', 'Opmerkingen', 'text'],
        ],
        'ventilation' => [
            ['Reiniging', 'Filters vervangen', 'boolean'],
            ['Reiniging', 'Warmtewisselaar gereinigd', 'boolean'],
            ['Reiniging', 'Condensafvoer vrij', 'boolean'],
            ['Metingen', 'Luchtdebiet (m³/h)', 'number', ['range' => [180, 320]]],
            ['Functie', 'Bypass werkt', 'boolean'],
        ],
        'water' => [
            ['Veiligheid & F-gassen', 'Inlaatcombinatie getest', 'boolean'],
            ['Functie', 'Anode', 'radio', ['options' => ['Goed', 'Vervangen']]],
            ['Metingen', 'Temperatuur tapwater (°C)', 'number', ['range' => [55, 62]]],
        ],
        'cooling' => [
            ['Reiniging', 'Verdamper gereinigd', 'boolean'],
            ['Functie', 'Ontdooicyclus getest', 'boolean'],
            ['Metingen', 'Celtemperatuur (°C)', 'number', ['range' => [1, 4]]],
            ['Functie', 'Deurrubbers en deurverwarming', 'radio', ['options' => ['Goed', 'Aandacht nodig']]],
        ],
        'general' => [
            ['Functie', 'Functietest', 'boolean'],
            ['Functie', 'Firmware bijgewerkt', 'boolean'],
            ['Afronding', 'Opmerkingen', 'text'],
        ],
    ],

    /** What a mechanic writes under "Opmerkingen" on a visit that went fine. */
    'remarks' => [
        'Alles in orde, installatie werkt naar behoren.',
        'Filters waren flink vervuild, klant geadviseerd deze vaker te laten reinigen.',
        'Condensafvoer was deels verstopt, doorgespoten.',
        'Geen bijzonderheden.',
        'Klant tevreden, volgende onderhoudsbeurt over een jaar.',
        'Lichte vervuiling, gereinigd. Geen afwijkende waarden.',
    ],
];
