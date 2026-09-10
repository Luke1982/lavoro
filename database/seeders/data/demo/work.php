<?php

/**
 * The work the demo company does: what the appointments are called, what goes
 * wrong with each kind of installation, and what the mechanic wrote down after.
 *
 * Faults are keyed by the top of the product type tree, so a heat pump never
 * reports the complaint of an air conditioner.
 */
return [
    'event_types' => [
        'Periodieke controle' => '#388e3c',
        'Oplossen storing' => '#d32f2f',
        'Controle met storingen' => '#fbc02d',
        'Inventarisatie' => '#7b1fa2',
        'Installatie' => '#0284c7',
    ],

    'maintenance' => [
        'Airconditioning' => ['Jaarlijks onderhoud airco-installatie', 'Halfjaarlijks onderhoud klimaatinstallatie', 'Lekdichtheidscontrole F-gassen en onderhoud'],
        'Warmtepompen' => ['Periodiek onderhoud warmtepomp', 'Jaarlijkse controle warmtepompinstallatie'],
        'Verwarming' => ['Onderhoud CV-ketel inclusief rookgasanalyse', 'Jaarlijks ketelonderhoud'],
        'Ventilatie' => ['Filters vervangen en reinigen WTW-unit', 'Onderhoud ventilatiesysteem'],
        'Koeltechniek' => ['Onderhoud koelcel en condensing unit', 'Periodieke controle koelinstallatie'],
        'Warm tapwater' => ['Controle boiler en inlaatcombinatie'],
        'Regeltechniek' => ['Controle regeling en thermostaten'],
        'Zonne-energie' => ['Controle omvormer en opbrengst'],
    ],

    'faults' => [
        'Airconditioning' => [
            ['Airco koelt niet meer', 'Binnendeel blaast alleen lucht, het buitendeel slaat niet aan.', 'Communicatiekabel bij het buitendeel was gecorrodeerd; klemmen vervangen, installatie weer in bedrijf.'],
            ['Foutcode U4 op het binnendeel', 'Storing in de communicatie tussen binnen- en buitendeel, sinds de onweersbui van gisteren.', 'Printplaat buitendeel defect door overspanning; printplaat vervangen en overspanningsbeveiliging geadviseerd.'],
            ['Lekkage condenswater binnendeel', 'Water druppelt uit het binnendeel boven een bureau.', 'Condensafvoer verstopt door vervuiling; afvoer doorgespoten en lekbak gereinigd.'],
            ['Buitendeel maakt tikkend geluid', 'Sinds een paar dagen een tikkend geluid bij het opstarten.', 'Blad in de ventilator van het buitendeel; verwijderd en trillingsdempers nagelopen.'],
            ['Airco valt steeds uit', 'Unit schakelt na zo\'n tien minuten uit, geen foutcode zichtbaar.', 'Vervuilde condensor, hogedrukbeveiliging sprong eruit; condensor gereinigd, drukken weer normaal.'],
            ['Vreemde geur bij opstarten airco', 'Muffe geur bij het aanzetten van de koeling.', 'Verdamper en ventilatorrol gereinigd en gedesinfecteerd; klant geadviseerd filters vaker te reinigen.'],
        ],
        'Warmtepompen' => [
            ['Warmtepomp in storing, geen warm water', 'Display toont een storing, er komt geen warm water meer uit de kraan.', 'Flowsensor vervuild; vuilvanger gereinigd en installatie ontlucht. Warmtepomp weer in bedrijf.'],
            ['Waterdruk loopt terug', 'De druk zakt binnen een week van 1,8 naar 0,9 bar.', 'Lekkende automatische ontluchter vervangen, installatie bijgevuld tot 1,7 bar.'],
            ['Warmtepomp maakt veel geluid \'s nachts', 'Buren klagen over een zoemend geluid in de nacht.', 'Stille nachtstand ingesteld en trillingsdempers onder de unit vervangen.'],
            ['Vloerverwarming wordt niet warm', 'Beneden wordt het niet warmer dan 17 graden.', 'Stooklijn te laag ingesteld; stooklijn aangepast en groepen ingeregeld.'],
        ],
        'Verwarming' => [
            ['CV-ketel in storing, geen ontsteking', 'Ketel geeft een storingscode en start niet op.', 'Ontsteekelektrode vervangen en brander gereinigd; ketel ontsteekt weer normaal.'],
            ['Geen warm water, verwarming werkt wel', 'De radiatoren worden warm, maar er komt geen warm water uit de kraan.', 'Driewegklep vastgelopen; klep vervangen, tapwater weer op temperatuur.'],
            ['Ketel maakt borrelend geluid', 'Er klinkt een borrelend geluid uit de ketel en de radiatoren.', 'Lucht in de installatie; ontlucht en bijgevuld tot 1,8 bar.'],
            ['Waterdruk te laag, ketel slaat af', 'De ketel geeft aan dat de waterdruk te laag is.', 'Installatie bijgevuld; kleine lekkage bij radiatorkraan gerepareerd.'],
        ],
        'Ventilatie' => [
            ['WTW-unit maakt veel lawaai', 'Een brommend geluid vanaf zolder, vooral in stand 3.', 'Ventilator vervuild en uit balans; ventilatoren gereinigd, geluid verholpen.'],
            ['Filtermelding blijft branden', 'Na het vervangen van de filters blijft de melding staan.', 'Filterteller gereset en bediening opnieuw gekoppeld.'],
            ['Condenslekkage onder WTW-unit', 'Er staat water onder de WTW-unit op zolder.', 'Sifon van de condensafvoer was drooggevallen; gevuld en afvoer op afschot gelegd.'],
        ],
        'Koeltechniek' => [
            ['Koelcel komt niet op temperatuur', 'De cel staat op 9 graden, producten moeten eruit.', 'Condensor zwaar vervuild en ventilatormotor defect; motor vervangen, cel binnen een uur op 3 °C.'],
            ['IJsvorming op verdamper koelcel', 'Er zit een dikke ijslaag op de verdamper.', 'Ontdooitimer defect; timer vervangen en ontdooicyclus getest.'],
        ],
        'Warm tapwater' => [
            ['Boiler lekt bij de inlaatcombinatie', 'Er druppelt water uit het overstortventiel.', 'Inlaatcombinatie vervangen, druk in orde.'],
        ],
        'Regeltechniek' => [
            ['Thermostaat verliest verbinding', 'De thermostaat meldt regelmatig dat er geen verbinding met de ketel is.', 'OpenTherm-bekabeling opnieuw aangesloten en thermostaat geüpdatet.'],
        ],
        'Zonne-energie' => [
            ['Omvormer produceert niets', 'De app laat al drie dagen nul opbrengst zien.', 'Omvormer in isolatiefout door vocht in een connector; connector vervangen, omvormer weer in productie.'],
        ],
    ],

    'installations' => [
        'Levering en montage airco slaapkamer (2,5 kW)',
        'Uitbreiding multi split met extra binnendeel',
        'Vervangen CV-ketel door hybride warmtepomp',
        'Montage WTW-unit inclusief kanaalwerk zolder',
        'Levering en montage lucht-water warmtepomp',
    ],

    /** Keyed like the faults, so a survey is about something the customer actually has. */
    'surveys' => [
        'Airconditioning' => ['Opname voor uitbreiding airco-installatie', 'Inventarisatie klimaatinstallatie voor vervanging'],
        'Warmtepompen' => ['Opname voor offerte lucht-water warmtepomp', 'Adviesgesprek verduurzaming woning'],
        'Verwarming' => ['Opname vervanging CV-ketel door hybride warmtepomp', 'Adviesgesprek verduurzaming'],
        'Ventilatie' => ['Opname ventilatiesysteem en luchtkwaliteit'],
        'Koeltechniek' => ['Opname koelcel voor vervanging condensing unit'],
        'Warm tapwater' => ['Opname vervanging boiler door warmtepompboiler'],
        'Regeltechniek' => ['Advies zoneregeling en slimme thermostaten'],
        'Zonne-energie' => ['Opname uitbreiding zonnepanelen'],
    ],

    'incomplete' => [
        'Onderdeel niet op voorraad (printplaat buitendeel), besteld bij de groothandel. Retourbezoek inplannen.',
        'Klant niet aanwezig op de afgesproken tijd, niemand deed open.',
        'Werken op hoogte vraagt een hoogwerker; die is niet meegenomen. Opnieuw inplannen met hoogwerker.',
    ],

    /** Questions and reports that never needed a visit, or have not got one yet. */
    'remote_tickets' => [
        ['Vraag over instellen weekschema', 'Klant wil het schema van de thermostaat aanpassen voor de feestdagen.'],
        ['Offerteaanvraag extra binnendeel', 'Klant wil ook de zolderkamer laten koelen, graag een offerte.'],
        ['Onderhoudscontract verlengen', 'Contract loopt volgend kwartaal af, klant wil verlengen.'],
        ['Factuur onderhoud: vraag over btw', 'Klant vraagt of het onderhoud onder het lage btw-tarief valt.'],
    ],
];
