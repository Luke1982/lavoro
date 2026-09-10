<?php

/**
 * The demo company's customers, in and around the Betuwe.
 *
 * Businesses and households are invented; the streets and towns are real, so an
 * address on a map lands somewhere sensible. Every e-mail address ends in .demo,
 * which cannot exist -- nothing sent to these can reach anyone.
 *
 * 'installs' names a recipe from 'recipes' below: what is installed on site, and
 * therefore which machines, which maintenance and which faults this customer
 * produces. 'brand' is the brand they were sold, so the indoor and outdoor units
 * of one system match.
 */
return [
    /**
     * Town centres. Every address gets a spot within a kilometre or so of its
     * town's, so the map on the dashboard shows the work spread over the
     * region instead of saying nothing is on a known address.
     */
    'coordinates' => [
        'Tiel' => [51.8867, 5.4297], 'Culemborg' => [51.9553, 5.2275], 'Geldermalsen' => [51.8820, 5.2890],
        'Zaltbommel' => [51.8103, 5.2493], 'Houten' => [52.0286, 5.1683], 'Beesd' => [51.8883, 5.1906],
        'Leerdam' => [51.8930, 5.0921], 'Buren' => [51.9117, 5.3343], 'Maurik' => [51.9600, 5.4217],
        'Kesteren' => [51.9361, 5.5667], 'Meteren' => [51.8650, 5.2830], 'Utrecht' => [52.0907, 5.1214],
        'Nieuwegein' => [52.0292, 5.0808],
    ],

    'recipes' => [
        'office' => ['Buitendeel multi split' => 1, 'Binnendeel multi split' => 3, 'WTW-unit' => 1, 'Thermostaat' => 1],
        'server_room' => ['Buitendeel single split' => 2, 'Binnendeel wandmodel' => 2],
        'restaurant' => ['Buitendeel single split' => 1, 'Binnendeel cassette' => 2, 'Condensing unit' => 1, 'Koelcelverdamper' => 1, 'WTW-unit' => 1],
        'school' => ['Buitendeel VRF' => 1, 'Binnendeel kanaalmodel' => 4, 'CV-ketel' => 1],
        'hotel' => ['Buitendeel VRF' => 1, 'Binnendeel kanaalmodel' => 5, 'CV-ketel' => 2, 'Buffervat' => 1],
        'practice' => ['Buitendeel single split' => 2, 'Binnendeel wandmodel' => 2, 'WTW-unit' => 1],
        'shop' => ['Condensing unit' => 1, 'Koelcelverdamper' => 1, 'Buitendeel single split' => 1, 'Binnendeel cassette' => 1],
        'workshop' => ['Monoblock warmtepomp' => 1, 'Mechanische ventilatiebox' => 1, 'Elektrische boiler' => 1],
        'gym' => ['Buitendeel multi split' => 1, 'Binnendeel multi split' => 4, 'WTW-unit' => 1],
        'chalet' => ['Buitendeel single split' => 1, 'Binnendeel wandmodel' => 1],
        'home_airco' => ['Buitendeel single split' => 1, 'Binnendeel wandmodel' => 1],
        'home_multi' => ['Buitendeel multi split' => 1, 'Binnendeel multi split' => 2],
        'home_heatpump' => ['Split warmtepomp buitendeel' => 1, 'Hydrobox binnenunit' => 1, 'Thermostaat' => 1, 'WTW-unit' => 1],
        'home_monoblock' => ['Monoblock warmtepomp' => 1, 'Buffervat' => 1, 'Thermostaat' => 1],
        'home_hybrid' => ['Hybride warmtepomp' => 1, 'CV-ketel' => 1, 'Thermostaat' => 1],
        'home_cv' => ['CV-ketel' => 1, 'Thermostaat' => 1, 'Mechanische ventilatiebox' => 1],
        'home_ground' => ['Bodem-water warmtepomp' => 1, 'WTW-unit' => 1, 'Warmtepompboiler' => 1],
        'pv' => ['Omvormer' => 1, 'Zonnepaneel' => 1],
    ],

    /**
     * Households, put together from these lists. An installer's books are mostly
     * households, and a planning of five weeks needs more addresses than a
     * handful of businesses can give without visiting everyone twice.
     */
    'households' => [
        'count' => 130,
        'surnames' => [
            'de Jong', 'Jansen', 'de Vries', 'van den Berg', 'van Dijk', 'Bakker', 'Janssen', 'Visser', 'Smit', 'Meijer',
            'de Boer', 'Mulder', 'de Groot', 'Bos', 'Vos', 'Peters', 'Hendriks', 'van Leeuwen', 'Dekker', 'Brouwer',
            'Dijkstra', 'Smits', 'de Graaf', 'van der Meer', 'van der Linden', 'Kok', 'Jacobs', 'de Haan', 'Vermeulen',
            'van der Veen', 'van den Broek', 'de Bruijn', 'van der Heijden', 'Schouten', 'van Beek', 'Willems', 'van Vliet',
            'van de Ven', 'Hoekstra', 'Maas', 'Koster', 'van Dam', 'van der Wal', 'Prins', 'Blom', 'Huisman', 'Kaya',
            'El Idrissi', 'Nguyen', 'Öztürk', 'Kowalski', 'Postma', 'Timmermans', 'Lambregts', 'Kuipers', 'Wijnen',
            'van Wijk', 'Molenaar', 'Verbeek', 'van Rijn', 'Hermans', 'Sanders', 'Gerritsen', 'Aarts', 'van Loon',
            'Wouters', 'Evers', 'Martens', 'Claassen', 'Veenstra', 'Pol', 'Boon', 'Kroon', 'Hofman', 'Coenen', 'Rutten',
            'van Doorn', 'Verschoor', 'Bouwman', 'van Os', 'Jonker', 'Mertens',
        ],
        'first_names' => [
            'Jan', 'Petra', 'Mark', 'Ingrid', 'Sander', 'Linda', 'Rob', 'Monique', 'Erik', 'Sandra', 'Dennis', 'Esther',
            'Bart', 'Karin', 'Joost', 'Anja', 'Wouter', 'Marloes', 'Hans', 'Ellen', 'Rik', 'Nicole', 'Tim', 'Femke',
            'Ahmed', 'Fatma', 'Wei', 'Anna', 'Luuk', 'Sophie',
        ],
        'towns' => [
            'Tiel' => ['postal' => '400', 'area' => '0344', 'streets' => ['Bachstraat', 'Mozartstraat', 'Beethovenlaan', 'Stationsstraat', 'Hoveniersweg', 'Ridderstraat', 'Laan van Westroijen']],
            'Culemborg' => ['postal' => '410', 'area' => '0345', 'streets' => ['Goilberdingerweg', 'Weidsteeg', 'Zandstraat', 'Triftweg', 'Varkensmarkt', 'Parallelweg']],
            'Geldermalsen' => ['postal' => '419', 'area' => '0345', 'streets' => ['Rijksstraatweg', 'Middelweg', 'Boerenstraat', 'Kerkstraat', 'Oude Kerkstraat']],
            'Zaltbommel' => ['postal' => '530', 'area' => '0418', 'streets' => ['Gamersestraat', 'Van Heemstraweg', 'Oliestraat', 'Nieuwstraat', 'Molenwal']],
            'Houten' => ['postal' => '399', 'area' => '030', 'streets' => ['Loerikseweg', 'Oude Dorp', 'Rondweg', 'Tolakkerweg', 'Het Kruispunt']],
            'Beesd' => ['postal' => '415', 'area' => '0345', 'streets' => ['Voorstraat', 'Dorpsstraat', 'Kerkweg', 'Molenweg']],
            'Leerdam' => ['postal' => '414', 'area' => '0345', 'streets' => ['Hoogstraat', 'Vlietskade', 'Oosterwijksestraat', 'Kerkstraat']],
            'Buren' => ['postal' => '411', 'area' => '0344', 'streets' => ['Voorstraat', 'Kerkstraat', 'Rodeweg']],
            'Maurik' => ['postal' => '402', 'area' => '0344', 'streets' => ['Rijnbandijk', 'Dorpsstraat', 'Kerkstraat']],
            'Kesteren' => ['postal' => '404', 'area' => '0488', 'streets' => ['Hoofdstraat', 'Nedereindsestraat', 'Spoorstraat']],
        ],
        /** Recipe => [weight, brands that install it]. */
        'installs' => [
            'home_cv' => [25, ['Intergas', 'Remeha', 'Vaillant', 'Nefit Bosch', 'ATAG']],
            'home_airco' => [20, ['Daikin', 'Mitsubishi Electric', 'Toshiba', 'LG', 'Panasonic']],
            'home_hybrid' => [15, ['Intergas', 'Remeha']],
            'home_multi' => [10, ['Daikin', 'Mitsubishi Electric']],
            'home_heatpump' => [10, ['Mitsubishi Electric', 'Daikin']],
            'home_monoblock' => [12, ['Vaillant', 'Daikin', 'LG', 'Bosch']],
            'home_ground' => [5, ['Itho Daalderop', 'NIBE']],
        ],
        'solar' => 0.3,
        'contract' => 0.55,
    ],

    'customers' => [
        [
            'name' => 'Tandartspraktijk De Linde', 'address' => 'Ringweg 18', 'postal_code' => '4101 AR', 'city' => 'Culemborg',
            'phone' => '0345-512 408', 'email' => 'info@tandartsdelinde.demo', 'contact' => ['Anouk', 'Verhoeven', '06-41 22 87 19'],
            'installs' => ['practice'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Hotel Rivierzicht', 'address' => 'Waalkade 3', 'postal_code' => '4001 LE', 'city' => 'Tiel',
            'phone' => '0344-620 511', 'email' => 'receptie@hotelrivierzicht.demo', 'contact' => ['Marco', 'Brouwer', '06-18 45 66 02'],
            'installs' => ['hotel'], 'brand' => 'Mitsubishi Electric', 'contract' => 'Halfjaarlijks',
            'sites' => [['title' => 'Bijgebouw Waalzicht', 'address' => 'Waalkade 7', 'postal_code' => '4001 LE', 'city' => 'Tiel', 'installs' => ['chalet', 'chalet']]],
        ],
        [
            'name' => 'Bakkerij Van Ommeren', 'address' => 'Rijksstraatweg 41', 'postal_code' => '4191 CE', 'city' => 'Geldermalsen',
            'phone' => '0345-571 233', 'email' => 'bestellen@bakkerijvanommeren.demo', 'contact' => ['Gert', 'van Ommeren', '06-22 90 13 47'],
            'installs' => ['shop'], 'brand' => 'Toshiba', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Kinderopvang Het Klavertje', 'address' => 'Plantijnlaan 52', 'postal_code' => '3991 VR', 'city' => 'Houten',
            'phone' => '030-634 71 90', 'email' => 'locatie.houten@hetklavertje.demo', 'contact' => ['Petra', 'Donkers', '06-55 71 30 88'],
            'installs' => ['office', 'home_cv'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Autobedrijf Van Leeuwen', 'address' => 'Ambachtsweg 9', 'postal_code' => '5301 LH', 'city' => 'Zaltbommel',
            'phone' => '0418-512 660', 'email' => 'werkplaats@autovanleeuwen.demo', 'contact' => ['Rob', 'van Leeuwen', '06-10 57 22 31'],
            'installs' => ['workshop', 'pv'], 'brand' => 'Vaillant', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Fysiotherapie Waalkade', 'address' => 'Hoogeinde 12', 'postal_code' => '4001 JS', 'city' => 'Tiel',
            'phone' => '0344-634 822', 'email' => 'praktijk@fysiowaalkade.demo', 'contact' => ['Iris', 'Lammers', '06-33 18 50 76'],
            'installs' => ['practice'], 'brand' => 'Mitsubishi Electric',
        ],
        [
            'name' => 'Restaurant De Gouden Karper', 'address' => 'Markt 16', 'postal_code' => '4101 BZ', 'city' => 'Culemborg',
            'phone' => '0345-535 901', 'email' => 'keuken@goudenkarper.demo', 'contact' => ['Hassan', 'Yilmaz', '06-47 28 91 15'],
            'installs' => ['restaurant'], 'brand' => 'Daikin', 'contract' => 'Halfjaarlijks', 'priority' => true,
        ],
        [
            'name' => 'Meijer & Van Dam Advocaten', 'address' => 'Maliebaan 64', 'postal_code' => '3581 CT', 'city' => 'Utrecht',
            'phone' => '030-231 44 70', 'email' => 'secretariaat@meijervandam.demo', 'contact' => ['Charlotte', 'Meijer', '06-20 66 41 90'],
            'installs' => ['office'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Basisschool De Wegwijzer', 'address' => 'Kerkstraat 30', 'postal_code' => '4194 WA', 'city' => 'Meteren',
            'phone' => '0345-581 677', 'email' => 'directie@dewegwijzer.demo', 'contact' => ['Wim', 'Scholten', '06-38 12 95 04'],
            'installs' => ['school'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'VvE Kantoorgebouw Parkhuis', 'address' => 'Structuurbaan 2', 'postal_code' => '3439 MB', 'city' => 'Nieuwegein',
            'phone' => '030-600 18 55', 'email' => 'beheer@vveparkhuis.demo', 'contact' => ['Arjen', 'Koster', '06-15 78 34 60'],
            'installs' => ['office', 'office'], 'brand' => 'Mitsubishi Electric', 'contract' => 'Halfjaarlijks',
        ],
        [
            'name' => 'Drukkerij Rijnland', 'address' => 'Nijverheidsweg 5', 'postal_code' => '4153 BV', 'city' => 'Beesd',
            'phone' => '0345-681 344', 'email' => 'productie@drukkerijrijnland.demo', 'contact' => ['Henk', 'Vos', '06-29 40 17 83'],
            'installs' => ['workshop', 'office'], 'brand' => 'LG',
        ],
        [
            'name' => 'Huisartsenpraktijk Molenzicht', 'address' => 'Molenweg 23', 'postal_code' => '4141 AP', 'city' => 'Leerdam',
            'phone' => '0345-613 780', 'email' => 'assistente@hapmolenzicht.demo', 'contact' => ['Esther', 'de Haan', '06-44 86 22 59'],
            'installs' => ['practice'], 'brand' => 'Panasonic', 'contract' => 'Jaarlijks',
        ],
        [
            'name' => 'Transportbedrijf Van den Berg', 'address' => 'Kellenseweg 11', 'postal_code' => '4004 JC', 'city' => 'Tiel',
            'phone' => '0344-651 200', 'email' => 'planning@vandenbergtransport.demo', 'contact' => ['Dennis', 'van den Berg', '06-12 33 76 48'],
            'installs' => ['office', 'server_room', 'pv'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
            'sites' => [['title' => 'Distributiecentrum', 'address' => 'Latensteinse Rondweg 30', 'postal_code' => '4005 LA', 'city' => 'Tiel', 'installs' => ['workshop']]],
        ],
        [
            'name' => 'Makelaardij Het Oosten', 'address' => 'Waterstraat 8', 'postal_code' => '5301 AH', 'city' => 'Zaltbommel',
            'phone' => '0418-516 040', 'email' => 'info@makelaardijhetoosten.demo', 'contact' => ['Sabine', 'Oosterhout', '06-50 21 44 97'],
            'installs' => ['home_multi'], 'brand' => 'Mitsubishi Electric',
        ],
        [
            'name' => 'FitPoint Culemborg', 'address' => 'Parallelweg 40', 'postal_code' => '4102 BJ', 'city' => 'Culemborg',
            'phone' => '0345-547 210', 'email' => 'club@fitpointculemborg.demo', 'contact' => ['Kevin', 'Jacobs', '06-26 83 50 11'],
            'installs' => ['gym'], 'brand' => 'LG', 'contract' => 'Halfjaarlijks',
        ],
        [
            'name' => 'Datalink ICT-diensten', 'address' => 'Meidoornkade 22', 'postal_code' => '3992 AE', 'city' => 'Houten',
            'phone' => '030-760 90 30', 'email' => 'noc@datalink-ict.demo', 'contact' => ['Joost', 'Wagemakers', '06-31 62 08 74'],
            'installs' => ['server_room', 'office'], 'brand' => 'Mitsubishi Electric', 'contract' => 'Maandelijks', 'priority' => true,
        ],
        [
            'name' => 'Knip & Co Kapsalon', 'address' => 'Voorstraat 19', 'postal_code' => '4116 BD', 'city' => 'Buren',
            'phone' => '0344-571 606', 'email' => 'afspraak@knipenco.demo', 'contact' => ['Naomi', 'Pauw', '06-42 17 90 35'],
            'installs' => ['home_airco'], 'brand' => 'Toshiba',
        ],
        [
            'name' => 'Vakantiepark De Lingehoeve', 'address' => 'Lingedijk 88', 'postal_code' => '4153 RM', 'city' => 'Beesd',
            'phone' => '0345-682 555', 'email' => 'receptie@lingehoeve.demo', 'contact' => ['Marleen', 'Aarts', '06-35 90 44 28'],
            'installs' => ['home_cv'], 'brand' => 'Daikin', 'contract' => 'Jaarlijks',
            'sites' => [
                ['title' => 'Chalet 4 · De Reiger', 'address' => 'Lingedijk 88-4', 'postal_code' => '4153 RM', 'city' => 'Beesd', 'installs' => ['chalet']],
                ['title' => 'Chalet 7 · De Ooievaar', 'address' => 'Lingedijk 88-7', 'postal_code' => '4153 RM', 'city' => 'Beesd', 'installs' => ['chalet']],
                ['title' => 'Chalet 12 · De Fuut', 'address' => 'Lingedijk 88-12', 'postal_code' => '4153 RM', 'city' => 'Beesd', 'installs' => ['chalet']],
            ],
        ],
        [
            'name' => 'Buurtsuper Dekker', 'address' => 'Hoofdstraat 61', 'postal_code' => '4041 AC', 'city' => 'Kesteren',
            'phone' => '0488-481 902', 'email' => 'winkel@buurtsuperdekker.demo', 'contact' => ['Frank', 'Dekker', '06-19 55 20 63'],
            'installs' => ['shop', 'shop'], 'brand' => 'Daikin', 'contract' => 'Halfjaarlijks', 'priority' => true,
        ],

        [
            'name' => 'Fam. Van den Heuvel', 'address' => 'Rijnstraat 14', 'postal_code' => '4191 WP', 'city' => 'Geldermalsen',
            'phone' => '06-24 81 67 30', 'email' => 'vandenheuvel@thuis.demo', 'contact' => ['Marieke', 'van den Heuvel', '06-24 81 67 30'],
            'installs' => ['home_heatpump', 'home_airco'], 'brand' => 'Mitsubishi Electric', 'contract' => 'Jaarlijks', 'private' => true,
        ],
        [
            'name' => 'P. de Wit', 'address' => 'Sniederslaan 101', 'postal_code' => '4003 BA', 'city' => 'Tiel',
            'phone' => '06-37 20 11 84', 'email' => 'p.dewit@thuis.demo', 'contact' => ['Peter', 'de Wit', '06-37 20 11 84'],
            'installs' => ['home_multi'], 'brand' => 'Daikin', 'private' => true,
        ],
        [
            'name' => 'Fam. Kramer', 'address' => 'Goilberdingerweg 72', 'postal_code' => '4106 LJ', 'city' => 'Culemborg',
            'phone' => '06-53 09 72 16', 'email' => 'kramer@thuis.demo', 'contact' => ['Joris', 'Kramer', '06-53 09 72 16'],
            'installs' => ['home_hybrid', 'pv'], 'brand' => 'Intergas', 'contract' => 'Jaarlijks', 'private' => true,
        ],
        [
            'name' => 'A. Groen', 'address' => 'Dorpsstraat 5', 'postal_code' => '4194 TA', 'city' => 'Meteren',
            'phone' => '06-11 48 92 55', 'email' => 'a.groen@thuis.demo', 'contact' => ['Annelies', 'Groen', '06-11 48 92 55'],
            'installs' => ['home_cv'], 'brand' => 'Remeha', 'contract' => 'Jaarlijks', 'private' => true,
        ],
        [
            'name' => 'Fam. Bosman', 'address' => 'Heuvelweg 3', 'postal_code' => '4021 VM', 'city' => 'Maurik',
            'phone' => '06-45 70 36 21', 'email' => 'bosman@thuis.demo', 'contact' => ['Remco', 'Bosman', '06-45 70 36 21'],
            'installs' => ['home_ground'], 'brand' => 'Itho Daalderop', 'contract' => 'Jaarlijks', 'private' => true,
        ],
        [
            'name' => 'Fam. Yıldız', 'address' => 'Burgemeester Meslaan 44', 'postal_code' => '4003 CD', 'city' => 'Tiel',
            'phone' => '06-28 94 13 70', 'email' => 'yildiz@thuis.demo', 'contact' => ['Emre', 'Yıldız', '06-28 94 13 70'],
            'installs' => ['home_monoblock'], 'brand' => 'Vaillant', 'private' => true,
        ],
    ],
];
