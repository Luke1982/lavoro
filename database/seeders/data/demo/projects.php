<?php

/**
 * The projects: work too big for one visit, led by the project leader, with a
 * budget the project manager keeps.
 *
 * Days count from this week's Monday, like the rest of the planning, and are
 * weekdays only. Each phase becomes an installation order with its tasks; a
 * phase with days is in the planner for the team, full days, and one without
 * is still waiting for a date. What lies before now is done. People are named
 * by the part of their login before the @.
 *
 * Bas is away on days 9 to 11 and Yusuf leaves at noon on Fridays, so neither
 * is planned for a full day then.
 *
 * Budget lines: what it is, the budget, what has been spent, and a note.
 */
return [
    [
        'title' => 'Klimaatinstallatie nieuw kantoor Van den Berg',
        'customer' => 'Transportbedrijf Van den Berg',
        'site' => 'Distributiecentrum',
        'status' => 'Gestart',
        'start' => -24,
        'end' => 17,
        'team' => ['jeroen', 'tom'],
        'description' => "VRF-systeem met warmteterugwinning voor de nieuwe kantoorvleugel van het distributiecentrum (1.200 m²).\n14 cassettes, 2 buitendelen op het dak, centrale regeling en koppeling met het gebouwbeheersysteem.",
        'milestones' => [
            ['Opname en ontwerp', -24, 'kees'],
            ['Opdracht getekend', -17, 'ruud'],
            ['Leidingwerk gereed', -5, 'kees'],
            ['Binnendelen gemonteerd', 2, 'kees'],
            ['Inbedrijfstelling', 15, 'kees'],
            ['Oplevering en instructie gebruikers', 17, 'kees'],
        ],
        'phases' => [
            ['Leidingwerk koper en condensafvoer', [-7, -6, -5], [
                'Leidingtracé uitzetten en beugelen', 'Koperleiding solderen en isoleren', 'Condensafvoer aanleggen op afschot', 'Leidingwerk afpersen met stikstof',
            ]],
            ['Montage cassettes kantoorvleugel', [0, 1, 2], [
                'Plafondopeningen uitzetten met de aannemer', 'Cassettes ophangen en waterpas stellen', 'Aansluiten koudemiddel en condensafvoer', 'Bekabeling naar de regeling trekken',
            ]],
            ['Montage buitendelen en dakdoorvoeren', [8, 9], [
                'Hijsplan en kraan afstemmen', 'Buitendelen plaatsen op trillingsdempers', 'Dakdoorvoeren waterdicht afwerken',
            ]],
            ['Inbedrijfstelling VRF-systeem', [15], [
                'Vacumeren en koudemiddel bijvullen', 'Adressering en proefdraaien', 'Koppeling gebouwbeheer testen', 'F-gassenregistratie bijwerken',
            ]],
        ],
        'budget' => [
            ['VRF-systeem: 14 cassettes, 2 buitendelen', 48600, 47250, 'Geleverd, restant na oplevering'],
            ['Leidingwerk, isolatie en condensafvoer', 9800, 10420, 'Extra tracé naar de vergaderzaal'],
            ['Arbeid montage (circa 180 uur)', 21600, 12960, ''],
            ['Hoogwerker en kraan', 2400, 1650, 'Kraan volgende week'],
            ['Regeling en koppeling gebouwbeheer', 5200, 0, 'Na inbedrijfstelling'],
        ],
    ],
    [
        'title' => 'Verduurzaming basisschool De Wegwijzer',
        'customer' => 'Basisschool De Wegwijzer',
        'status' => 'Afgerond',
        'start' => -70,
        'end' => -35,
        'team' => ['bas', 'yusuf'],
        'description' => "Drie hybride warmtepompen naast de bestaande ketels, aangepast afgiftesysteem in de lokalen en een nieuwe regeling per vleugel.\nUitgevoerd in de zomervakantie.",
        'milestones' => [
            ['Subsidieaanvraag ingediend', -84, 'eva'],
            ['Opdracht schoolbestuur', -70, 'ruud'],
            ['Installatie gereed', -55, 'kees'],
            ['Oplevering en instructie conciërge', -49, 'kees'],
            ['Eindafrekening verstuurd', -35, 'eva'],
        ],
        'phases' => [
            ['Demontage oude CV-installatie', [-63], [
                'Installatie aftappen en afkoppelen', 'Oude ketels demonteren en afvoeren',
            ]],
            ['Plaatsen hybride warmtepompen', [-62, -61], [
                'Buitendelen plaatsen op het platte dak', 'Binnenunits monteren in het ketelhok', 'Elektrische aansluiting en groepen',
            ]],
            ['Afgiftesysteem aanpassen en inregelen', [-56, -55], [
                'Radiatoren vervangen in lokaal 3 en 4', 'Waterzijdig inregelen per vleugel', 'Regeling per vleugel instellen',
            ]],
            ['Oplevering en instructie', [-49], [
                'Proefdraaien met de conciërge', 'Opleverdossier overhandigen',
            ]],
        ],
        'budget' => [
            ['Hybride warmtepompen (3x)', 27900, 27900, ''],
            ['Aanpassen afgiftesysteem', 8400, 9150, 'Twee radiatoren extra vervangen'],
            ['Arbeid (circa 120 uur)', 14400, 13680, ''],
            ['Afvoer oude ketels', 650, 650, ''],
        ],
    ],
    [
        'title' => 'Vervanging koelinstallatie Buurtsuper Dekker',
        'customer' => 'Buurtsuper Dekker',
        'status' => 'Niet gestart',
        'start' => 18,
        'end' => 23,
        'team' => ['niels', 'tom'],
        'description' => "De R404A-installatie gaat eruit: een CO2-boosterunit voor de koelmeubelen en de koelcel, met warmteterugwinning voor de winkelverwarming.\nDe winkel blijft open; koelmeubelen gaan per sectie over.",
        'milestones' => [
            ['Offerte akkoord', -10, 'ruud'],
            ['Materiaal besteld', -3, 'eva'],
            ['Start werkzaamheden', 18, 'kees'],
            ['Oplevering en F-gassenregistratie', 23, 'kees'],
        ],
        'phases' => [
            ['Demontage oude condensing unit', [18], [
                'Koudemiddel terugwinnen en registreren', 'Oude unit demonteren en afvoeren',
            ]],
            ['Plaatsen CO2-booster en leidingwerk', [21, 22], [
                'Booster plaatsen in de technische ruimte', 'RVS-leidingwerk naar de koelmeubelen', 'Koelmeubelen per sectie omzetten',
            ]],
            ['Inbedrijfstelling en F-gassenregistratie', [], [
                'Afpersen en vacumeren', 'Inbedrijfstelling en temperatuurregistratie', 'Logboek en registratie bijwerken',
            ]],
        ],
        'budget' => [
            ['CO2-boosterunit met warmteterugwinning', 38500, 19250, 'Aanbetaling 50%'],
            ['Leidingwerk RVS en koper', 7200, 0, ''],
            ['Arbeid (circa 90 uur)', 11200, 0, ''],
            ['Terugwinnen en verwerken oud koudemiddel', 1350, 0, ''],
        ],
    ],
    [
        'title' => 'Woonboerderij van het gas af',
        'customer' => 'Fam. Kramer',
        'status' => 'Gestart',
        'start' => -21,
        'end' => 14,
        'team' => ['bas', 'yusuf'],
        'description' => "Volledig elektrische lucht-water warmtepomp met boilervat, vloerverwarming beneden en een WTW-unit op zolder.\nDe gasaansluiting wordt na oplevering verwijderd.",
        'milestones' => [
            ['Warmteverliesberekening', -21, 'kees'],
            ['ISDE-subsidie aangevraagd', -14, 'fatima'],
            ['Warmtepomp in bedrijf', -4, 'kees'],
            ['WTW-unit geplaatst', 3, 'kees'],
            ['Oplevering en uitleg', 14, 'kees'],
        ],
        'phases' => [
            ['Plaatsen buitendeel en binnenunit', [-5, -4], [
                'Buitendeel plaatsen op de betonpoer', 'Binnenunit en boilervat monteren', 'Aansluiten op de vloerverwarming', 'Elektra en eigen groep',
            ]],
            ['Montage WTW-unit en kanaalwerk zolder', [2, 3], [
                'WTW-unit ophangen op zolder', 'Kanaalwerk en ventielen per kamer', 'Condensafvoer aansluiten',
            ]],
            ['Inregelen, oplevering en uitleg', [14], [
                'Stooklijn instellen', 'Ventilatie inregelen per ventiel', 'Uitleg aan de bewoners',
            ]],
        ],
        'budget' => [
            ['Lucht-water warmtepomp 8 kW met boilervat', 11250, 11250, ''],
            ['WTW-unit met kanaalwerk', 4850, 2425, 'Kanalen geleverd'],
            ['Arbeid (circa 48 uur)', 5400, 2880, ''],
            ['Elektra en groepenkast', 950, 950, ''],
        ],
    ],
    [
        'title' => 'Redundante koeling serverruimte Datalink',
        'customer' => 'Datalink ICT-diensten',
        'status' => 'Niet gestart',
        'start' => 28,
        'end' => 31,
        'team' => ['jeroen', 'niels'],
        'description' => "Twee close-control units in N+1-opstelling, zodat de serverruimte koel blijft als een unit uitvalt of in onderhoud is.\nKoppeling met de monitoring van Datalink.",
        'milestones' => [
            ['Opname serverruimte', -6, 'kees'],
            ['Offerte uitgebracht', -3, 'ruud'],
            ['Opdracht verwacht', 4, 'ruud'],
            ['Installatie', 28, 'kees'],
        ],
        'phases' => [
            ['Plaatsen twee close-control units', [], [
                'Units plaatsen en aansluiten', 'Condensafvoer en bevochtiging', 'Omschakeling bij uitval testen',
            ]],
            ['Koppeling monitoring en alarmering', [], [
                'Alarmcontacten koppelen', 'Test met de meldkamer van Datalink',
            ]],
        ],
        'budget' => [
            ['Twee close-control units', 22400, 0, ''],
            ['Leidingwerk en condensafvoer', 3100, 0, ''],
            ['Arbeid (circa 40 uur)', 4800, 0, ''],
            ['Koppeling monitoring', 1500, 0, ''],
        ],
    ],
];
