<?php

/**
 * The demo catalogue: a product type tree for a climate and installation
 * company, and real products on every leaf of it.
 *
 * Model names and indicative prices are the manufacturers' own, as they appear
 * in any wholesaler's price list; prices are excluding VAT. 'art' names the
 * drawing ProductArt makes for the type, 'checks' the checklist from
 * checklists.php a mechanic fills in for it.
 */
return [
    'brands' => [
        'Daikin' => ['accent' => '#0097E0', 'serial' => 'J'],
        'Mitsubishi Electric' => ['accent' => '#E60012', 'serial' => '4'],
        'Toshiba' => ['accent' => '#D62027', 'serial' => 'T'],
        'LG' => ['accent' => '#A50034', 'serial' => '0'],
        'Panasonic' => ['accent' => '#0049AB', 'serial' => 'P'],
        'Vaillant' => ['accent' => '#00917A', 'serial' => '21'],
        'Intergas' => ['accent' => '#D0021B', 'serial' => 'IG'],
        'Remeha' => ['accent' => '#E2001A', 'serial' => 'R'],
        'Nefit Bosch' => ['accent' => '#C8102E', 'serial' => '7'],
        'Bosch' => ['accent' => '#E20015', 'serial' => '8'],
        'ATAG' => ['accent' => '#1E3A8A', 'serial' => 'A'],
        'NIBE' => ['accent' => '#003B5C', 'serial' => '06'],
        'Itho Daalderop' => ['accent' => '#0072BC', 'serial' => 'ID'],
        'Zehnder' => ['accent' => '#E4002B', 'serial' => 'Z'],
        'Brink' => ['accent' => '#0061A8', 'serial' => 'B'],
        'Atlantic' => ['accent' => '#0055A4', 'serial' => 'AT'],
        'Ariston' => ['accent' => '#003DA5', 'serial' => 'AR'],
        'tado°' => ['accent' => '#FF7A00', 'serial' => 'RU'],
        'Honeywell' => ['accent' => '#E4002B', 'serial' => 'H'],
        'Google Nest' => ['accent' => '#4285F4', 'serial' => '09'],
        'SMA' => ['accent' => '#CC0000', 'serial' => '30'],
        'SolarEdge' => ['accent' => '#E31B23', 'serial' => '7E'],
        'LONGi' => ['accent' => '#E1251B', 'serial' => 'LR'],
        'Jinko' => ['accent' => '#1D4E89', 'serial' => 'JK'],
        'Danfoss' => ['accent' => '#E2000F', 'serial' => 'DF'],
        'Güntner' => ['accent' => '#004B87', 'serial' => 'GU'],
    ],

    'types' => [
        'Airconditioning' => ['certificate_days' => 365, 'children' => [
            'Single split' => ['children' => [
                'Binnendeel wandmodel' => ['art' => 'wall', 'checks' => 'airco_indoor'],
                'Binnendeel cassette' => ['art' => 'cassette', 'checks' => 'airco_indoor'],
                'Binnendeel vloermodel' => ['art' => 'console', 'checks' => 'airco_indoor'],
                'Buitendeel single split' => ['art' => 'outdoor', 'checks' => 'airco_outdoor'],
            ]],
            'Multi split' => ['children' => [
                'Binnendeel multi split' => ['art' => 'wall', 'checks' => 'airco_indoor'],
                'Buitendeel multi split' => ['art' => 'outdoor_multi', 'checks' => 'airco_outdoor'],
            ]],
            'VRF-systemen' => ['children' => [
                'Buitendeel VRF' => ['art' => 'outdoor_large', 'checks' => 'airco_outdoor'],
                'Binnendeel kanaalmodel' => ['art' => 'ducted', 'checks' => 'airco_indoor'],
            ]],
        ]],
        'Warmtepompen' => ['certificate_days' => 365, 'children' => [
            'Lucht-water warmtepomp' => ['children' => [
                'Monoblock warmtepomp' => ['art' => 'heatpump', 'checks' => 'heatpump'],
                'Split warmtepomp buitendeel' => ['art' => 'outdoor', 'checks' => 'heatpump'],
                'Hydrobox binnenunit' => ['art' => 'hydrobox', 'checks' => 'heatpump'],
            ]],
            'Hybride warmtepomp' => ['art' => 'heatpump', 'checks' => 'heatpump'],
            'Bodem-water warmtepomp' => ['art' => 'cabinet', 'checks' => 'heatpump'],
            'Warmtepompboiler' => ['art' => 'cylinder', 'checks' => 'water'],
        ]],
        'Verwarming' => ['certificate_days' => 365, 'children' => [
            'CV-ketel' => ['art' => 'boiler', 'checks' => 'boiler'],
            'Buffervat' => ['art' => 'cylinder', 'checks' => 'water'],
        ]],
        'Ventilatie' => ['certificate_days' => 730, 'children' => [
            'WTW-unit' => ['art' => 'hru', 'checks' => 'ventilation'],
            'Mechanische ventilatiebox' => ['art' => 'mvbox', 'checks' => 'ventilation'],
        ]],
        'Warm tapwater' => ['children' => [
            'Elektrische boiler' => ['art' => 'cylinder', 'checks' => 'water'],
        ]],
        'Regeltechniek' => ['children' => [
            'Thermostaat' => ['art' => 'thermostat', 'checks' => 'general'],
        ]],
        'Zonne-energie' => ['children' => [
            'Omvormer' => ['art' => 'inverter', 'checks' => 'general'],
            'Zonnepaneel' => ['art' => 'panel', 'checks' => 'general'],
        ]],
        'Koeltechniek' => ['certificate_days' => 365, 'children' => [
            'Condensing unit' => ['art' => 'outdoor', 'checks' => 'airco_outdoor'],
            'Koelcelverdamper' => ['art' => 'evaporator', 'checks' => 'cooling'],
        ]],
    ],

    'products' => [
        ['Binnendeel wandmodel', 'Daikin', 'Perfera FTXM25R', '2,5 kW koelen / 3,4 kW verwarmen · R32 · A+++', 689, 452, '5 jaar'],
        ['Binnendeel wandmodel', 'Daikin', 'Emura FTXJ25AW', '2,5 kW · designmodel mat wit · Onecta-app', 1045, 690, '5 jaar'],
        ['Binnendeel wandmodel', 'Mitsubishi Electric', 'MSZ-LN25VG', 'Diamond · 2,5 kW · R32 · dubbele plasma-filter', 959, 630, '5 jaar'],
        ['Binnendeel wandmodel', 'Mitsubishi Electric', 'MSZ-AP35VG', '3,5 kW koelen / 4,0 kW verwarmen · R32', 685, 450, '5 jaar'],
        ['Binnendeel wandmodel', 'Toshiba', 'Haori RAS-B10N4KVRG-E', '2,5 kW · textiel front · R32', 799, 525, '5 jaar'],
        ['Binnendeel wandmodel', 'LG', 'ArtCool AC09BK', '2,5 kW · spiegelfront · R32', 719, 470, '5 jaar'],
        ['Binnendeel wandmodel', 'Panasonic', 'Etherea CS-Z25ZKEW', '2,5 kW · nanoe X · R32', 745, 490, '5 jaar'],

        ['Binnendeel cassette', 'Daikin', 'FCAG35B', '3,4 kW · 4-weg rondom uitblaas · 840x840', 1165, 765, '3 jaar'],
        ['Binnendeel cassette', 'Mitsubishi Electric', 'SLZ-M35FA', '3,5 kW · 600x600 · past in systeemplafond', 1090, 715, '3 jaar'],

        ['Binnendeel vloermodel', 'Daikin', 'Perfera FVXM35A', '3,5 kW vloermodel · stille nachtstand', 1195, 785, '5 jaar'],
        ['Binnendeel vloermodel', 'Mitsubishi Electric', 'MFZ-KT35VG', '3,5 kW · onder- en bovenuitblaas', 1149, 755, '5 jaar'],

        ['Buitendeel single split', 'Daikin', 'RXM25R', '2,5 kW · R32 · -25 °C verwarmen', 815, 535, '5 jaar'],
        ['Buitendeel single split', 'Mitsubishi Electric', 'MUZ-LN25VG', '2,5 kW · R32 · A+++', 965, 635, '5 jaar'],
        ['Buitendeel single split', 'Toshiba', 'RAS-10J2AVSG-E1', '2,5 kW · R32', 695, 455, '5 jaar'],
        ['Buitendeel single split', 'LG', 'AC09BK.UL2', '2,5 kW · R32', 689, 450, '5 jaar'],
        ['Buitendeel single split', 'Panasonic', 'CU-Z25ZKE', '2,5 kW · R32', 725, 475, '5 jaar'],

        ['Binnendeel multi split', 'Daikin', 'Perfera CTXM15R', '1,5 kW · alleen voor multi split', 545, 360, '5 jaar'],
        ['Binnendeel multi split', 'Mitsubishi Electric', 'MSZ-AP20VG', '2,0 kW · multi split', 555, 365, '5 jaar'],

        ['Buitendeel multi split', 'Daikin', '2MXM50A', '5,0 kW · 2 aansluitingen · R32', 1545, 1015, '5 jaar'],
        ['Buitendeel multi split', 'Daikin', '3MXM52A', '5,2 kW · 3 aansluitingen · R32', 1895, 1245, '5 jaar'],
        ['Buitendeel multi split', 'Daikin', '4MXM80A', '8,0 kW · 4 aansluitingen · R32', 2690, 1765, '5 jaar'],
        ['Buitendeel multi split', 'Mitsubishi Electric', 'MXZ-3F54VF', '5,4 kW · 3 aansluitingen · R32', 1985, 1300, '5 jaar'],

        ['Buitendeel VRF', 'Daikin', 'VRV 5 RXYA8A', '22,4 kW · warmtepomp · R32', 9850, 6450, '3 jaar'],
        ['Buitendeel VRF', 'Mitsubishi Electric', 'City Multi PUMY-SP112VKM', '12,5 kW · tot 9 binnendelen', 6390, 4190, '3 jaar'],

        ['Binnendeel kanaalmodel', 'Daikin', 'FXSA50A', '5,6 kW · kanaalmodel voor VRV', 1540, 1010, '3 jaar'],
        ['Binnendeel kanaalmodel', 'Mitsubishi Electric', 'PEFY-P40VMA-E', '4,5 kW · kanaalmodel City Multi', 1395, 915, '3 jaar'],

        ['Monoblock warmtepomp', 'Vaillant', 'aroTHERM plus VWL 75/6 A', '7 kW · R290 · aanvoer tot 75 °C', 8950, 5870, '5 jaar'],
        ['Monoblock warmtepomp', 'Daikin', 'Altherma 3 M EBLA08E3V3', '8 kW · R32 monoblock', 7690, 5040, '5 jaar'],
        ['Monoblock warmtepomp', 'LG', 'Therma V HM091MR.U44', '9 kW · R32 monoblock', 6490, 4250, '5 jaar'],
        ['Monoblock warmtepomp', 'Bosch', 'Compress 7400i AW 7', '7 kW · R290 · stil ontwerp', 8490, 5560, '5 jaar'],

        ['Split warmtepomp buitendeel', 'Mitsubishi Electric', 'Ecodan PUZ-WM85VAA', '8,5 kW · R32 · split', 5390, 3530, '5 jaar'],

        ['Hydrobox binnenunit', 'Mitsubishi Electric', 'Ecodan ERST20D-VM2D', 'Binnenunit met 200 l tapwaterboiler', 4290, 2810, '5 jaar'],
        ['Hydrobox binnenunit', 'Daikin', 'Altherma 3 R EHVH08S23E6V', 'Binnenunit met 230 l boiler', 4190, 2745, '5 jaar'],

        ['Hybride warmtepomp', 'Intergas', 'Xtend', '5 kW hybride · werkt samen met Intergas-ketel', 4495, 2945, '5 jaar'],
        ['Hybride warmtepomp', 'Remeha', 'Elga Ace 4 kW', '4 kW hybride · R32', 3995, 2615, '5 jaar'],

        ['Bodem-water warmtepomp', 'Itho Daalderop', 'WPU 5G', '6 kW bodemwarmtepomp · met boiler', 11950, 7830, '5 jaar'],
        ['Bodem-water warmtepomp', 'NIBE', 'S1255-6', '6 kW · geïntegreerde boiler 180 l', 12450, 8160, '5 jaar'],

        ['Warmtepompboiler', 'Atlantic', 'Explorer V4 270 l', 'Warmtepompboiler 270 l · COP 3,3', 2690, 1760, '5 jaar'],
        ['Warmtepompboiler', 'Ariston', 'Nuos Evo A+ 110', 'Warmtepompboiler 110 l · wandmodel', 1790, 1170, '5 jaar'],

        ['CV-ketel', 'Intergas', 'Xtreme 36', 'HR-combiketel · CW5 · 92% warmwaterrendement', 1895, 1240, '10 jaar warmtewisselaar'],
        ['CV-ketel', 'Remeha', 'Tzerra Ace 28c', 'HR-combiketel · CW4 · compact', 1745, 1145, '10 jaar warmtewisselaar'],
        ['CV-ketel', 'Vaillant', 'ecoTEC plus VHR 30-36/5-5', 'HR-combiketel · CW5', 1985, 1300, '10 jaar warmtewisselaar'],
        ['CV-ketel', 'Nefit Bosch', 'ProLine NxT 28', 'HR-combiketel · CW4', 1690, 1110, '5 jaar'],
        ['CV-ketel', 'ATAG', 'i36C', 'HR-combiketel · CW5 · 15 jaar garantie warmtewisselaar', 1920, 1260, '15 jaar warmtewisselaar'],

        ['Buffervat', 'Vaillant', 'allSTOR VPS 300/3-5', 'Buffervat 300 l · voor warmtepomp', 1590, 1040, '2 jaar'],

        ['WTW-unit', 'Zehnder', 'ComfoAir Q350', '350 m³/h · 96% rendement · ComfoConnect', 3290, 2155, '5 jaar'],
        ['WTW-unit', 'Brink', 'Flair 325', '325 m³/h · bypass · Brink Home', 2890, 1895, '5 jaar'],
        ['WTW-unit', 'Itho Daalderop', 'HRU ECO 350', '350 m³/h · RFT-bediening', 2450, 1605, '5 jaar'],

        ['Mechanische ventilatiebox', 'Itho Daalderop', 'CVE-S ECO RFT', 'Energiezuinige MV-box · vochtsensor', 385, 255, '5 jaar'],
        ['Mechanische ventilatiebox', 'Zehnder', 'ComfoFan S', 'MV-box · 3 standen', 345, 225, '2 jaar'],

        ['Elektrische boiler', 'Itho Daalderop', 'Mono-plus 80 l', 'Elektrische boiler 80 l · 2,2 kW', 649, 425, '5 jaar ketel'],
        ['Elektrische boiler', 'Ariston', 'Velis Evo 80', 'Elektrische boiler 80 l · plat model', 589, 385, '5 jaar ketel'],

        ['Thermostaat', 'tado°', 'Slimme Thermostaat X', 'Slimme thermostaat · OpenTherm · app', 179, 118, '2 jaar'],
        ['Thermostaat', 'Honeywell', 'Lyric T6', 'Slimme thermostaat · geofencing', 199, 130, '2 jaar'],
        ['Thermostaat', 'Google Nest', 'Learning Thermostat', 'Zelflerende thermostaat', 249, 163, '2 jaar'],
        ['Thermostaat', 'Remeha', 'eTwist', 'Slimme thermostaat voor Remeha-ketels', 219, 143, '2 jaar'],

        ['Omvormer', 'SMA', 'Sunny Boy 5.0', '5 kW · 2 MPP-trackers · SMA Energy-app', 1390, 910, '5 jaar'],
        ['Omvormer', 'SolarEdge', 'SE5K-RWS', '5 kW · met power optimizers', 1590, 1040, '12 jaar'],

        ['Zonnepaneel', 'LONGi', 'Hi-MO 6 430 Wp', 'Monokristallijn · full black', 159, 104, '15 jaar product'],
        ['Zonnepaneel', 'Jinko', 'Tiger Neo 435 Wp', 'N-type · full black', 165, 108, '15 jaar product'],

        ['Condensing unit', 'Danfoss', 'Optyma Plus OP-LPHM018', 'Condensing unit voor koelcel · R449A', 2890, 1895, '2 jaar'],
        ['Koelcelverdamper', 'Güntner', 'S-GACC RX 040.1', 'Plafondverdamper koelcel · 2 ventilatoren', 1190, 780, '2 jaar'],
    ],
];
