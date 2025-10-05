<?php

return [
    ['name' => 'Controlepunten', 'order' => 1, 'items' => [
        [
            'name' => 'Zijn alle opschriften op het typeplaatje aanwezig en kloppen ze?',
            'type' => 'radio',
            'values' => ['Ja', 'Nee', 'N.v.t.'],
        ],
        [
            'name' => 'Bevestiging uitlijnsysteem',
            'type' => 'radio',
            'values' => ['Goed', 'Slecht', 'N.v.t.'],
        ],
        [
            'name' => 'Opspanklemmen in goede conditie?',
            'type' => 'radio',
            'values' => ['Ja', 'Nee', 'N.v.t.'],
        ],
        [
            'name' => 'Draaiplaten in goede conditie?',
            'type' => 'radio',
            'values' => ['Ja', 'Nee', 'N.v.t.'],
        ],
        [
            'name' => 'Bekabeling in goede conditie?',
            'type' => 'radio',
            'values' => ['Ja', 'Nee', 'N.v.t.'],
        ],
        [
            'name' => 'Werking PC',
            'type' => 'radio',
            'values' => ['Goed', 'Slecht', 'N.v.t.'],
        ],
        [
            'name' => 'Kalibratie sporing - camber - caster',
            'type' => 'radio',
            'values' => ['Gekalibreerd', 'Niet gekalibreerd', 'N.v.t.'],
        ],
        [
            'name' => 'Instellingen softwareverloop',
            'type' => 'radio',
            'values' => ['Goed', 'Slecht', 'N.v.t.'],
        ],
        [
            'name' => 'Update software uitgevoerd?',
            'type' => 'radio',
            'values' => ['Ja', 'Nee', 'N.v.t.'],
        ],
        [
            'name' => 'Database versie',
            'type' => 'text',
        ],
    ]],
];
