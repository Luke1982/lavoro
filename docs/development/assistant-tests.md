# Testvragen voor de assistent

Dit is de vaste lijst. Verander je een toolbeschrijving, draai dan de hele lijst
opnieuw. Zonder vaste lijst weet je alleen dat de vraag die je net aanpaste beter
gaat, niet of je twee andere hebt gesloopt.

Draaien:

```bash
php artisan assistant:ask "<vraag>" --tenant=Demo
php artisan assistant:ask "<vraag>" --tenant=Demo --user=2        # als een monteur
php artisan assistant:ask "<vraag>" --tenant=Demo --show-results  # ook wat de tool teruggaf
```

De assistent leest de gegevens van één klant, dus `--tenant` (een id of een
naam, `php artisan tenants:list` toont ze) is verplicht. Lokaal is dat de
demo-klant; die wordt elke nacht opnieuw opgebouwd, dus nummers hieronder
kloppen morgen niet meer.

Klant- en werkbonnummers hieronder komen uit de database waarin je test. Pas ze
aan als ze daar niet bestaan.

## Één tool

| Vraag | Verwachte tool |
|---|---|
| Zoek de klant Prins Klimaatservice | `find_customer` |
| Welke werkbonnen staan er open? | `search_service_orders` |
| Welke machine heeft serienummer SN-PR87051? | `find_asset` |
| Geef me een overzicht van klant 24 | `summarize_customer` |
| Wat is er de afgelopen week gewijzigd? | `search_activity` |

## Twee tools achter elkaar

Hier moet hij eerst een id ophalen en dat dan gebruiken. Gaat dit mis, dan is dat
bijna altijd de beschrijving van de eerste tool.

| Vraag | Verwachte volgorde |
|---|---|
| Welke werkbonnen staan open bij Prins Klimaatservice? | `find_customer` → `search_service_orders` |
| Welke machines van Prins Klimaatservice moeten binnenkort onderhoud? | `find_customer` → `find_asset` |
| Hoe staat Van der Meulen Installatie ervoor? | `find_customer` → `summarize_customer` |

## Rechten

Draai deze als `--user=2`. Hij hoort te weigeren en te zeggen dat het aan de
rechten ligt — niet dat de klant niet bestaat.

| Vraag | Verwacht |
|---|---|
| Welke werkbonnen heb ik openstaan? | alleen die van hemzelf, niet alle |
| Wat is het e-mailadres van Prins Klimaatservice? | geweigerd, uitgelegd als rechten |
| Geef me een overzicht van klant 24 | geweigerd |

## Niet verzinnen

Hier moet hij zeggen dat hij het niet weet. Verzint hij een nummer of een naam,
dan is dat de ernstigste fout die deze lijst kan vinden.

| Vraag | Verwacht |
|---|---|
| Wat is het telefoonnummer van Bakkerij De Vries in Groningen? | niet gevonden, niets verzonnen |
| Wanneer is werkbon 999999 afgesloten? | niet gevonden |
| Hoeveel omzet hebben we vorige maand gedraaid? | zegt dat hij dat niet kan opzoeken |

## Handleiding

Vragen over hoe Lavoro zelf werkt horen naar `read_manual` te gaan — en technische
vragen over een apparaat juist naar `read_documentation`. Kiest hij de verkeerde
van de twee, dan zijn de beschrijvingen van die tools niet scherp genoeg.

| Vraag | Verwacht |
|---|---|
| Hoe sluit ik een werkbon af? | `read_manual`, antwoord uit het hoofdstuk Werkbonnen |
| Wat betekent de fase gefactureerd? | `read_manual`, "gefactureerd telt als gesloten" |
| Waar stel ik de rechten van een gebruiker in? | `read_manual`, hoofdstuk over rollen en rechten |
| Hoe koppel ik mijn Google Agenda? | `read_manual`, hoofdstuk Koppelingen |
| Wat is het koudemiddel van machine 173? | `read_documentation`, niet `read_manual` |
| Hoe werkt de voorraad als ik materiaal op een werkbon boek? | `read_manual`, hoofdstuk Materialen en voorraad |

Staat het antwoord niet in de handleiding, dan hoort hij dat te zeggen — niet
alsnog uit zijn hoofd te antwoorden.

## Geschiedenis

Let op: regels van vóór de omslag naar de nieuwe activiteitenlogging hangen nog
niet aan een record en blijven daarom buiten beeld. Wijzig eerst zelf iets en
vraag daar dan naar, anders lijkt de tool stuk terwijl het de oude data is.

```bash
php artisan tinker --execute='auth()->login(App\Models\User::find(1));
  App\Models\ServiceOrder::find(47)->update(["description" => "Testwijziging"]);'
php artisan assistant:ask "Wie heeft werkbon 47 het laatst gewijzigd en wat is er veranderd?"
```

Verwacht: naam, tijdstip, en de waarde ervoor en erna.

## Waar je op let

- Pakt hij de juiste tool, en in de juiste volgorde?
- Geeft hij een leeg resultaat terug als "niets gevonden", of verzint hij iets?
- Zegt hij bij een weigering dat het aan de rechten ligt, in plaats van dat iets
  niet bestaat?
- Blijft het Nederlands, ook in de zinnetjes tussendoor?
- Hoeveel tool-rondes? Meer dan twee voor een gewone vraag is meestal een teken
  dat een beschrijving onduidelijk is.

## Automatisch: `assistant:eval`

De lijst hierboven is voor één vraag met de hand. Hele gesprekken — een getekende
offerte tot en met de afspraak, een planning met een vervolgvraag, een afspraak
verzetten, een prijsformule die opgeslagen wordt — draai je met:

```bash
APP_ENV=local php artisan assistant:eval --list                      # scenario's, rol, en waarom er een niet kan
APP_ENV=local php artisan assistant:eval --suite=offerte            # offerte, planning, prijzen
APP_ENV=local php artisan assistant:eval --scenario=offerte.echt --repeat=3
APP_ENV=local php artisan assistant:eval --suite=planning --provider=anthropic_light   # alles op Haiku
```

### Op welke klanten ze mogen draaien

De eval en de probe spelen met echte gegevens en kosten echt geld. `APP_ENV=local`
is daarbij geen bescherming: gekopieerd naar een server zegt dat ook daar "niet
productie". Daarom draaien ze naast de weigering in productie alleen op een
klant die op `ASSISTANT_EVAL_TENANTS` staat (`config('assistant.evals.tenants')`):
ids of namen, gescheiden door komma's. Standaard is die lijst leeg, en dan
weigeren ze elke klant, ook `--list`, met een melding die zegt waarom.

Die lijst zet je zelf, in de `.env.local` van je eigen machine (het script
`scripts/tenancy/dev.sh` raakt hem niet aan; `--fresh` gooit `.env.local` wel
weg, zet hem daarna dus opnieuw):

```bash
ASSISTANT_EVAL_TENANTS=Demo
```

Zet hem niet in de opdracht zelf in documentatie of scripts: een opdracht die
de toestemming meedraagt, neemt die mee naar elke machine waar hij geplakt wordt.

### `--provider`, `--repeat` en `--suite`

`--provider` neemt alleen een aanbieder die vragen kan beantwoorden: een met
een API-sleutel en niet een die voor een eigen taak gereserveerd is (`sorter`,
`eval_user`, `eval_judge`). Een andere wordt geweigerd voordat er iets gebeurt,
met de lijst waaruit je kunt kiezen. `--repeat` is 1 of meer. Een `--suite` die
niet bestaat, of een `--scenario` dat niet bij de gekozen suite hoort, wordt
ook vooraf geweigerd, met die reden.

`--list` maakt niets aan: het leest alleen welke bestanden een scenario
meestuurt (naam en soort) en of `pdftoppm` op het pad staat. De verzonnen
offertes en het prijslijstje worden pas gemaakt als een run ze echt meestuurt.

Wat er gebeurt:

- Elk scenario loopt via dezelfde weg als het chatvenster (`AssistantSession`):
  dezelfde systeemprompt, dezelfde sortering, dezelfde bestanden en feiten.
- Elk scenario noemt wie het vraagt: planner, monteur of beheerder
  (`EvalUsers`). Een monteur is iemand met het monteursprofiel die ook echt
  afspraken heeft. De superbeheerder van MajorLabel wordt nooit gekozen.
- Een tweede model (Haiku, `eval_user`) speelt de gebruiker. Het kent alleen
  wat in het scenario staat en doet wat iemand in het venster kan: op één
  bevestigingsknop klikken, een keuze maken, iets typen of stoppen. Het hoeft
  niet te klikken; klopt een knop niet, dan kan het ook nee zeggen. Na een
  geslaagde klik gaat het gesprek vanzelf verder, net als in het venster. Een
  mislukte klik (of een verlopen bevestiging) komt terug bij de gebruiker, die
  opnieuw beslist.
- Alles gebeurt in een transactie op de database van de klant, en een op de
  centrale database, die na afloop allebei worden teruggedraaid. Op de
  centrale staat de meter (`assistant_usage`): bleef die staan, dan ging een
  eval af van het AI-tegoed van de klant. Autocommit staat in die transacties
  uit, zodat een deadlock die de transactie al terugdraait er niet voor zorgt
  dat wat daarna komt toch blijft staan; het gaat pas weer aan na een gelukte
  terugdraai, en lukt die niet, dan wordt de verbinding gesloten. In productie
  draaien de eval en de probe niet. Mail, notificaties en jobs worden onderschept. De
  schijven `public` en `local` worden voor de run vervangen door een eigen map
  die aan het eind wordt weggegooid: een terugdraai haalt geen bestand weg, en
  `save_document` zet de offerte op `public`. Bestaande foto's en documenten
  van de klant zijn daardoor tijdens een eval niet te zien.
- Daarna volgen de checks: telbare dingen, zoals de juiste klant, het aantal
  op de taak, de afspraak niet vóór de levertijd en de prijs die de opgeslagen
  formule echt rekent (in hele centen). Ze lezen terug wat er in de database
  staat, vóór het terugdraaien. Elk scenario krijgt ook de check "gesprek liep
  netjes af": alleen een gebruiker die zelf stopt telt; een fout, een ongeldige
  zet van de gesimuleerde gebruiker of afkappen na te veel zetten niet.
- Een derde model (Opus, `eval_judge`) beoordeelt wat alleen een lezer kan
  zien: Nederlands, kort, niets verzonnen, één vraag tegelijk. Het ziet per
  beurt de tools met hun resultaat (lange resultaten ingekort, met een
  markering) en wat de gebruiker daarna klikte, koos of typte. Een run die
  afbrak vóór de eerste beurt wordt niet beoordeeld: er is niets te lezen.
- Per run komen een transcript (`.md`) en een regel in `results.jsonl` in
  `storage/assistant-evals/<moment>_<code>/` (lokale tijd). Die bevatten
  klantgegevens en staan niet in git, net als de echte offertes in
  `storage/assistant-evals/fixtures/`. De verzonnen offertes en het
  prijslijstje worden daar pas gemaakt als een scenario ze nodig heeft, onder
  een naam met een hash van de inhoud.
- Een scenario waarvan het bestand of de gegevens (klanten, monteurs,
  plangroepen, formules, soorten) bij deze klant ontbreken, wordt
  overgeslagen in plaats van onterecht te slagen. `prijzen.toepassen` ook
  als Hotel Rivierzicht geen onderhoudscontracten heeft: "niets toe te
  passen" is dan het goede antwoord en zou als geslaagd tellen. Een
  `echte-offerte.json` die geen geldige JSON is of een veld mist, maakt
  alleen `offerte.echt` onbeschikbaar, met de reden in `--list`.
- Het commando eindigt met een fout zodra één check faalde, een run afbrak of
  het beoordelen mislukte. Een criterium dat de beoordelaar afkeurt, doet dat
  bewust niet: dat is het oordeel van een lezer, geen telling. Elk afgekeurd
  criterium staat onder de run (`~ oordeel: ...`), en aan het eind staat
  hoeveel criteria er over alle runs zijn afgekeurd.

Elke run kost echt geld op de API-sleutel, en het commando zegt hoeveel. Een
volledige offerte kost ongeveer €0,40, een planningsvraag een paar cent. Een
beurt die halverwege misgaat, wordt geprijsd uit de meter (`assistant_usage`)
en staat in het transcript als "uit de meter". Een beurt die lukt, telt ook
wat de meter meer heeft dan het antwoord: het sorteren van de vraag staat
alleen daar, en zo tellen beide kanten dezelfde aanroepen.

De verzonnen offertes noemen alleen overduidelijk verzonnen contactgegevens:
adressen op `example.nl`, nummers van nullen, postcodes `0000 ..` en straten
die niet bestaan. Hotel Rivierzicht is een klant uit de demo en houdt het adres
dat hij daar heeft.

Scenario's staan in `app/Domain/Assistant/Evals/`: `QuoteScenarios`
(offertes), `PlanningScenarios` en `PricingScenarios`; `EvalScenarios` somt ze
op. Het echte offertescenario (`offerte.echt`) noemt een echte klant: de pdf en
alles wat het scenario erover weet (persona, klant, offertenummer, producten,
contract) staan in `storage/assistant-evals/fixtures/echte-offerte.json`, buiten
git. Ontbreekt dat bestand, dan meldt `--list` het en wordt het scenario
overgeslagen.

De history die naar het model gaat, bouwt de eval in PHP (`EvalRunner::historyFor`)
zoals het venster dat in `resources/js/Utilities/assistantHistory.js` doet, en
de tekst van een klik op keuzes (`EvalRunner::picked`) zoals `choiceAnswer`
daar. De grenzen (hoeveel beurten, hoeveel tekens per vraag en per antwoord)
staan op `AssistantSession`.

Vragen en verdergaan na een klik lopen allebei via `AssistantSession`, net als
de eval. Daardoor weigert ook verdergaan (`POST` naar proceed) nu met een 422
"Er is geen model ingesteld dat bestanden kan lezen" als het gesprek een
bestand heeft dat geen enkel ingesteld model kan openen. Voorheen ging het
bestand dan toch mee en kwam er een antwoord over een bestand dat niemand
gelezen had. Zo'n weigering wordt niet in `assistant_questions` vastgelegd,
net als bij een vraag. Beide kanten draaien dezelfde gevallen uit
`tests/fixtures/assistant-history.json`, in `AssistantHistorySharedTest` en
`assistantHistoryShared.spec.js`; verander je de een, dan faalt de ander tot
hij weer hetzelfde doet.

## Automatisch: `assistant:probe`

Waar `assistant:eval` nagaat of bekende gesprekken goed gaan, zoekt
`assistant:probe` uit wat de assistent nog niet kan. Het stelt een brede reeks
alledaagse vragen (`ProbeQuestions`), elk één keer, als iemand in de rol van de
groep: planner, monteur, kantoor en beheer (die laatste twee als beheerder).

```bash
APP_ENV=local php artisan assistant:probe
APP_ENV=local php artisan assistant:probe --group=monteur --limit=5
```

Ook de probe draait alleen op een klant uit `ASSISTANT_EVAL_TENANTS` (zie
hierboven), en nooit in productie.

- Elke vraag loopt in een eigen transactie die wordt teruggedraaid; er wordt
  niets bevestigd, dus niets uitgevoerd. Mail, notificaties en jobs worden
  onderschept, de schijven blijven de echte (er wordt niets geschreven, en zo
  kan de assistent bestaande foto's wel lezen).
- Het model van de beoordelaar deelt elk antwoord in: beantwoord,
  doorgevraagd, kan_niet, half of verzonnen, met bij de laatste drie wat er
  zou moeten kunnen. Een indeling die daar niet bij hoort, staat als `?` in
  het rapport; het rapport wordt hoe dan ook geschreven.
- Het rapport komt in `storage/assistant-evals/probe_<moment>_<code>.md`:
  bovenaan wat er ontbreekt, gegroepeerd, daaronder elke vraag.
- Het commando eindigt met een fout als een vraag afbrak, het indelen
  mislukte of de beoordelaar een antwoord oversloeg.
