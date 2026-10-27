<?php

/**
 * The demo company and the people in it.
 *
 * Every login uses the password in 'password'. The first person is the one the
 * demo login lands on; the others each show what their role sees, so a demo can
 * switch to "the planner" or "a mechanic" without making anything up.
 *
 * Addresses are on lavorofsm.nl, so a demo shows our own domain. An address
 * points at one tenant, so none of these may be a real login elsewhere. No mail
 * reaches them: the demo has no mail settings of its own, and without those a
 * tenant sends nothing.
 *
 * The faces are in photos/users, generated: none of these people exist.
 */
return [
    'company' => [
        'name' => 'Demo Klimaattechniek B.V.',
        'address_line1' => 'Energieweg 14',
        'postal_code' => '4004 JK',
        'city' => 'Tiel',
        'country' => 'NL',
    ],

    'password' => 'demo',

    'plan_groups' => [
        'Team Airco' => '#2563eb',
        'Team Warmte' => '#ea580c',
        'Service & ventilatie' => '#16a34a',
    ],

    'people' => [
        [
            'name' => 'Sanne de Vries', 'email' => 'demo@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['admin', 'Gebruikersbeheer'],
            'look' => ['background' => '#dbeafe', 'skin' => 'light', 'hair' => 'long', 'hair_color' => '#8a4b1f', 'shirt' => '#1d4ed8'],
        ],
        [
            'name' => 'Mark Janssen', 'email' => 'mark@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Planner'],
            'look' => ['background' => '#fee2e2', 'skin' => 'light', 'hair' => 'side', 'hair_color' => '#3f2a1d', 'shirt' => '#334155', 'glasses' => true],
        ],
        [
            'name' => 'Lisa Bakker', 'email' => 'lisa@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Binnendienst'],
            'look' => ['background' => '#fce7f3', 'skin' => 'light', 'hair' => 'bob', 'hair_color' => '#d97706', 'shirt' => '#be185d'],
        ],
        [
            'name' => 'Fatima el Amrani', 'email' => 'fatima@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Administratie', 'HR'],
            'look' => ['background' => '#dcfce7', 'skin' => 'tan', 'hair' => 'bun', 'hair_color' => '#1f1410', 'shirt' => '#0f766e'],
        ],
        [
            'name' => 'Ruud Hendriks', 'email' => 'ruud@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Verkoop'],
            'look' => ['background' => '#e0f2fe', 'skin' => 'light', 'hair' => 'bald', 'hair_color' => '#6b7280', 'shirt' => '#0369a1', 'glasses' => true, 'beard' => true],
        ],
        [
            'name' => 'Kees van Dijk', 'email' => 'kees@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Projectleider'],
            'look' => ['background' => '#fef9c3', 'skin' => 'light', 'hair' => 'short', 'hair_color' => '#9ca3af', 'shirt' => '#4d7c0f'],
        ],
        [
            'name' => 'Eva Smit', 'email' => 'eva@lavorofsm.nl', 'seat' => 'office',
            'roles' => ['Projectmanager'],
            'look' => ['background' => '#ede9fe', 'skin' => 'light', 'hair' => 'long', 'hair_color' => '#1f2937', 'shirt' => '#6d28d9'],
        ],

        [
            'name' => 'Jeroen Mulder', 'email' => 'jeroen@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Team Airco'],
            'look' => ['background' => '#e0e7ff', 'skin' => 'light', 'hair' => 'short', 'hair_color' => '#78350f', 'shirt' => '#1e40af', 'beard' => true],
        ],
        [
            'name' => 'Tom de Groot', 'email' => 'tom@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Team Airco'],
            'look' => ['background' => '#ecfccb', 'skin' => 'light', 'hair' => 'side', 'hair_color' => '#fbbf24', 'shirt' => '#15803d'],
        ],
        [
            'name' => 'Niels Peters', 'email' => 'niels@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Team Airco', 'Service & ventilatie'],
            'look' => ['background' => '#cffafe', 'skin' => 'light', 'hair' => 'buzz', 'hair_color' => '#3f2a1d', 'shirt' => '#0e7490', 'glasses' => true],
        ],
        [
            'name' => 'Bas Visser', 'email' => 'bas@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Team Warmte'],
            'look' => ['background' => '#ffedd5', 'skin' => 'light', 'hair' => 'short', 'hair_color' => '#1f2937', 'shirt' => '#c2410c'],
        ],
        [
            'name' => 'Yusuf Kaya', 'email' => 'yusuf@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Team Warmte'],
            'look' => ['background' => '#fef3c7', 'skin' => 'medium', 'hair' => 'buzz', 'hair_color' => '#1f1410', 'shirt' => '#ea580c', 'beard' => true],
        ],
        [
            'name' => 'Daan Willems', 'email' => 'daan@lavorofsm.nl', 'seat' => 'field', 'mechanic' => true,
            'roles' => ['Monteur'], 'groups' => ['Service & ventilatie'],
            'look' => ['background' => '#f3e8ff', 'skin' => 'dark', 'hair' => 'curly', 'hair_color' => '#111827', 'shirt' => '#7c3aed'],
        ],
    ],

    /**
     * Absence, relative to the Monday of the current week. The planner shows it,
     * and a demo planning that works around a holiday is more convincing than one
     * in which everyone is always there. weekly_on counts from 0 for Monday, as
     * the planner does.
     */
    'absence' => [
        ['email' => 'bas@lavorofsm.nl', 'label' => 'Vakantie', 'from_day' => 9, 'to_day' => 11],
        ['email' => 'yusuf@lavorofsm.nl', 'label' => 'Vrijdagmiddag vrij', 'weekly_on' => 4, 'from' => '12:00', 'to' => '17:00'],
    ],
];
