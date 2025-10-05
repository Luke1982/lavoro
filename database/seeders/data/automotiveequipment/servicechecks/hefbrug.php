<?php

return [
    ['name' => 'Controle algemene aspecten', 'order' => 1, 'items' => [
        [
            'name' => 'Typeplaat, hefvermogen, lastverdeling en CE markering',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Verkorte gebruikersinstructie en logboek aanwezig',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Nederlandstalige handleiding aanwezig',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Onderhoud, reparatie en beproeving',
            'type' => 'radio',
            'values' => ['Op orde', 'Niet op orde', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle opstelling en constructie', 'order' => 2, 'items' => [
        [
            'name' => 'Bevestiging aan de vloer',
            'type' => 'radio',
            'values' => ['In orde', 'Niet in orde', 'N.v.t.'],
        ],
        [
            'name' => 'Draagconstructie en aanpassingen',
            'type' => 'radio',
            'values' => ['In orde', 'Niet in orde', 'N.v.t.'],
        ],
        [
            'name' => 'Horizontaalstand',
            'type' => 'radio',
            'values' => ['Binnen tolerantie', 'Buiten tolerantie', 'N.v.t.'],
        ],
        [
            'name' => 'Functiecontrole bewegende delen',
            'type' => 'radio',
            'values' => ['Correct', 'Niet correct', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle algemene toestand en slijtage onderhevige delen', 'order' => 3, 'items' => [
        [
            'name' => 'Staalkabels, kettingen en loopwielen',
            'type' => 'radio',
            'values' => ['Goed', 'Versleten', 'N.v.t.'],
        ],
        [
            'name' => 'Spindels/toplagers/draag en veiligheidsmoeren/tandwielkasten',
            'type' => 'radio',
            'values' => ['Goed', 'Versleten', 'N.v.t.'],
        ],
        [
            'name' => 'Mechanisch synchronisatie systeem gescheiden hefelementen',
            'type' => 'radio',
            'values' => ['Functioneert', 'Functioneert niet', 'N.v.t.'],
        ],
        [
            'name' => 'Aandrijfriemen/geleide rollen/stempelgeleiding',
            'type' => 'radio',
            'values' => ['Goed', 'Versleten', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle hydraulisch systeem', 'order' => 4, 'items' => [
        [
            'name' => 'Hydraulische cilinders',
            'type' => 'radio',
            'values' => ['Lekvrij', 'Lekkage', 'N.v.t.'],
        ],
        [
            'name' => 'Hydraulische slangen',
            'type' => 'radio',
            'values' => ['Goed', 'Beschadigd', 'N.v.t.'],
        ],
        [
            'name' => 'Overdrukventiel/manometer aansluiting/oliepeil',
            'type' => 'radio',
            'values' => ['Binnen norm', 'Buiten norm', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle veiligheidsvoorzieningen', 'order' => 5, 'items' => [
        [
            'name' => 'Afrijd- en voetbeveiliging',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Val- en obstakelbeveiliging/afscherming/snelheid/installatie',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Noodstopvoorziening',
            'type' => 'radio',
            'values' => ['Functioneert', 'Functioneert niet', 'N.v.t.'],
        ],
        [
            'name' => 'Verplaatsinrichting',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Vergrendeling draagarmen/opnamepads',
            'type' => 'radio',
            'values' => ['Functioneert', 'Functioneert niet', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle schakelaars en drukknoppen', 'order' => 6, 'items' => [
        [
            'name' => 'Onbevoegd en onbedoeld gebruik',
            'type' => 'radio',
            'values' => ['Beveiligd', 'Niet beveiligd', 'N.v.t.'],
        ],
        [
            'name' => 'Eindstandbegrenzing/bediening/markering/logische werking',
            'type' => 'radio',
            'values' => ['Correct', 'Niet correct', 'N.v.t.'],
        ],
    ]],
    ['name' => 'Controle overige aspecten', 'order' => 7, 'items' => [
        [
            'name' => 'Geïntegreerd wiervrij systeem',
            'type' => 'radio',
            'values' => ['Aanwezig', 'Ontbreekt', 'N.v.t.'],
        ],
        [
            'name' => 'Synchronisatie',
            'type' => 'radio',
            'values' => ['Correct', 'Niet correct', 'N.v.t.'],
        ],
        [
            'name' => 'Eisen aan pneumatische hefinrichtingen (7.3.1 t/m 7.3.8)',
            'type' => 'radio',
            'values' => ['Voldoet', 'Voldoet niet', 'N.v.t.'],
        ],
        [
            'name' => 'Mobiele hefkolommen/verplaatsbare hefbruggen',
            'type' => 'radio',
            'values' => ['Voldoet', 'Voldoet niet', 'N.v.t.'],
        ],
    ]],
];
