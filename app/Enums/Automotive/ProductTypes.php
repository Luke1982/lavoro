<?php

namespace App\Enums\Automotive;

enum ProductTypes: string
{
    case platenbank                      = 'Platenbank';
    case rollenremmentestbank            = 'Rollenremmentestbank';
    case pedaaldrukmeter                 = 'Pedaaldrukmeter';
    case viergastester                   = 'Viergastester';
    case roetmeter                       = 'Roetmeter';
    case nul_emissiekast                 = 'Nul emissiekast';
    case toerenteller                    = 'Toerenteller';
    case emissietest_toebehoren          = 'Emissietest toebehoren';
    case deeltjesteller                  = 'Deeltjesteller';
    case deeltjesteller_toebehoren       = 'Deeltjesteller toebehoren';
    case koplampafsteller                = 'Koplampafsteller';
    case aircomachine                    = 'Aircomachine';
    case airco_toebehoren                = 'Airco toebehoren';
    case versnellingsbakspoeler          = 'Versnellingsbakspoeler';
    case bandenwisselaar                 = 'Bandenwisselaar';
    case bandenwisselaar_toebehoren      = 'Bandenwisselaar toebehoren';
    case wielbalancer                    = 'Wielbalancer';
    case wielbalancer_toebehoren         = 'Wielbalancer toebehoren';
    case uitlijner                       = 'Uitlijner';
    case uitlijn_toebehoren              = 'Uitlijn toebehoren';
    case hefbrug_1_koloms                = '1-koloms hefbrug';
    case hefbrug_2_koloms                = '2-koloms hefbrug';
    case hefbrug_4_koloms                = '4-koloms hefbrug';
    case hefbrug_toebehoren              = 'Hefbrug toebehoren';
    case poetsbrug_bandenbrug            = 'Poetsbrug/Bandenbrug';
    case wielvrije_schaarhefbrug         = 'Wielvrije schaarhefbrug';
    case rijbanen_schaarhefbrug          = 'Rijbanen schaarhefbrug';
    case wielvrije_stempelhefbrug        = 'Wielvrije stempelhefbrug';
    case rijbanen_stempelhefbrug         = 'Rijbanen stempelhefbrug';
    case schade_poets_hefbrug            = 'Schade/poets hefbrug';
    case mobiele_hefkolom                = 'Mobiele hefkolom';
    case brugkrik                        = 'Brugkrik';
    case nog_onbekende_hefbrug           = 'Nog onbekende hefbrug';
    case bandenscanner                   = 'Bandenscanner';
    case tpms                            = 'TPMS';
    case appendage_apparatuur            = 'Appendage apparatuur';
    case diagnoseapparatuur              = 'Diagnoseapparatuur';
    case adas                            = 'ADAS';
    case adas_toebehoren                 = 'ADAS toebehoren';
    case wiellift                        = 'Wiellift';
    case wielenwasser                    = 'Wielenwasser';
    case compressor                      = 'Compressor';
    case diverse                         = 'Diverse';
    case sparepart_apk                   = 'Sparepart APK';
    case sparepart_hefinrichtingen       = 'Sparepart hefinrichtingen';
    case sparepart_wielservice           = 'Sparepart wielservice';
    case sparepart_overige               = 'Sparepart overige';
}
