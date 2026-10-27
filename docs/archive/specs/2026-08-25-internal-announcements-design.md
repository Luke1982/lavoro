# Interne aankondigingen

Een aankondiging is een bericht van de organisatie aan haar eigen mensen, dat
niet weggeklikt kan worden zonder dat vastligt wie het gelezen heeft. Denk aan
"vrijdag is het magazijn dicht" of "vanaf maandag boek je uren anders". Het
verschil met een melding is dat een melding informeert en een aankondiging een
bevestiging vraagt: er komt een balk onder in beeld die pas verdwijnt als de
gebruiker op **Begrepen** drukt, en dat moment wordt bewaard.

## Wat het moet doen

- Iemand met het recht om aankondigingen aan te maken schrijft een titel en een
  bericht, en kiest of het naar iedereen gaat of naar bepaalde gebruikers.
- Elke ontvanger krijgt de aankondiging onder in beeld te zien, op elke pagina,
  boven alles heen. Eén tegelijk, de oudste eerst.
- Op **Begrepen** wordt vastgelegd dát en wanneer die gebruiker bevestigde.
  Daarna schuift de volgende openstaande aankondiging in beeld.
- Wie de aankondiging beheert, ziet wie bevestigd heeft en wie nog niet.
- Een aankondiging kan een einddatum krijgen. Die telt mee — "zichtbaar tot en
  met" — dus de dag erna verdwijnt de balk, ook bij wie niet bevestigde. De
  aankondiging blijft in de lijst staan met de stand van zaken zoals die was.

## Doelgroep: een momentopname

Bij het opslaan wordt de doelgroep uitgeschreven naar concrete rijen: de gekozen
gebruikers, of bij "iedereen" alle niet-verwijderde gebruikers op dat moment.

Dat is een bewuste keuze boven het alternatief — de doelgroep steeds opnieuw
uitrekenen. Een vaste lijst geeft een noemer, dus "12 van de 20 bevestigd" is
een vraag die met één telling beantwoord is in plaats van met een vergelijking
tussen twee verzamelingen. En een nieuwe medewerker begint niet met een stapel
aankondigingen uit een verleden waar die niet bij was.

De prijs is dat een gebruiker die ná het aanmaken in dienst komt de aankondiging
niet krijgt. Wie dat wel wil, opent de aankondiging en voegt die persoon toe.

## Gegevensmodel

### `internal_announcements`

| kolom              | type               | betekenis                                    |
| ------------------ | ------------------ | -------------------------------------------- |
| `id`               | id                 |                                              |
| `title`            | string             | de kop in de balk                            |
| `body`             | text               | het bericht                                  |
| `is_for_everyone`  | boolean            | hoe de doelgroep gekozen is, voor weergave   |
| `expires_on`       | date, nullable     | leeg = blijft staan tot bevestigd            |
| `created_at`       | timestamp          |                                              |
| `updated_at`       | timestamp          |                                              |

`is_for_everyone` bepaalt niets aan de leverkant — de rijen in `userables` doen
dat — maar legt de bedoeling vast, zodat het scherm "iedereen" kan tonen in
plaats van tweeëntwintig namen.

`expires_on` is een datum en geen tijdstip, want dat is wat er gevraagd wordt.
"Zichtbaar tot en met 1 september" betekent dat 1 september meetelt, en dat is
precies `expires_on >= vandaag`. Een datumtijd zou dezelfde keuze om middernacht
laten aflopen, en dan moet er ergens stilletjes een einde-van-de-dag bij worden
opgeteld.

### Ontvangers en bevestigingen: `userables`

Ontvangers zijn rijen in het bestaande `userables`-pivot met
`type = 'announcement_recipient'`. Daar komt één nullable kolom bij:

```
acknowledged_at  datetime, nullable
```

Geen aparte tabel dus. `userables` draagt al kolommen die maar voor één soort
koppeling betekenis hebben (`breaktime`, `has_diverging_times`,
`diverging_start`, `diverging_end`), en de projectregel is expliciet: hergebruik
`userables` met de `type`-kolom in plaats van er een parallelle tabel naast te
zetten.

De rij is het antwoord op beide vragen die gesteld worden. Bestaat hij, dan
hoort deze gebruiker bij de doelgroep. Is `acknowledged_at` gevuld, dan heeft
die bevestigd, en wanneer.

### Rechten

Een migratie zaait vier rechten volgens de gebruikelijke naamgeving:

- `internalannouncement.read` — Aankondigingen bekijken
- `internalannouncement.create` — Aankondigingen aanmaken
- `internalannouncement.update` — Aankondigingen wijzigen
- `internalannouncement.delete` — Aankondigingen verwijderen

Bevestigen vraagt geen recht. Wie de aankondiging krijgt mag hem bevestigen, en
niemand anders; dat is een vraag over de ontvangerrij, niet over een rol.

## Achterkant

### `InternalAnnouncement`

Gebruikt `RecordsHistory` voor de tijdlijn en `HasOwner` voor wie hem verstuurde.

- `recipients()` — `morphToMany(User)` op `userable`, `wherePivot('type',
  'announcement_recipient')`, met `acknowledged_at` op het pivot.
- `syncRecipients(array $user_ids)` — voegt toe wat er nog niet is, haalt weg
  wat niet meer in de lijst staat **behalve** rijen die al bevestigd zijn: een
  bevestiging is een vastgelegd feit en wordt niet gewist door een
  doelgroepwijziging.
- `acknowledgeFor(User $user)` — zet `acknowledged_at` op nu, alleen als hij nog
  leeg is, en geeft terug of er iets veranderd is.
- `scopeOpen()` — nog niet verlopen.
- `openFor(User $user)` — de oudste openstaande, nog niet bevestigde
  aankondiging voor deze gebruiker, of `null`.
- `recipientRoster()` — ontvangers met hun bevestigingsmoment als ISO-tekst. Het
  pivot geeft een kale datumtekst, en die is niet in elke browser een datum.

Verwijderen ruimt in een `deleting`-haak de rijen in `userables` en
`activityables` op. Een morph-pivot heeft geen refererende sleutel die dat voor
je doet, dus zonder die haak blijven ze wijzen naar een record dat er niet meer
is. Dezelfde aanpak als `MaintenanceContract`.

### Signaal

Eén nieuw signaal: `App\Domain\Signals\Announcements\AnnouncementAcknowledged`,
met de aankondiging als onderwerp en de bevestigende gebruiker erbij.

Aanmaken, wijzigen en verwijderen dekt `RecordsHistory` al. Een schrijfactie op
een pivot legt niets vast, dus die bevestiging heeft een eigen signaal nodig om
op de tijdlijn te komen. Het signaal wordt door `Signals::dispatch` gestuurd en
door `RecordActivity` opgepikt via typehint-ontdekking; er is niets te
registreren.

Het model gebruikt daarnaast `HasActivities`, zodat die regels ook aan de
tijdlijn van de aankondiging zelf hangen en niet alleen in het logboek staan.

### Policy en Form Requests

`InternalAnnouncementPolicy` met `viewAny`, `view`, `create`, `update` en
`delete`, elk teruggebracht tot `hasPermission('internalannouncement.…')`.
`User::hasPermission` geeft `true` voor beheerders, dus die staan er niet apart
in. Daarnaast `acknowledge`, dat als enige geen recht raadpleegt maar de vraag
stelt die erbij hoort: staat er voor deze gebruiker een ontvangerrij bij deze
aankondiging, en loopt die nog. Een beheerder die niet in de doelgroep zit heeft
niets te bevestigen.

Of er al bevestigd is telt daar bewust niet in mee. Nog eens drukken — twee
tabbladen open, een trage verbinding — is niets doen, en de applicatie maakt van
elke 403 een rode melding; niets doen hoort er geen op te leveren. Dat het maar
één keer geteld wordt regelt `acknowledgeFor`, dat alleen een lege
`acknowledged_at` vult en teruggeeft of er echt iets veranderde.

Form Requests: `InternalAnnouncementReadRequest`, `…StoreRequest`,
`…UpdateRequest`, `…DestroyRequest` en `…AcknowledgeRequest`. Alle vijf
delegeren `authorize()` naar de policy; geen enkele raadpleegt zelf een recht.

Validatie in `rules()`: titel en bericht verplicht, `is_for_everyone` een
boolean, en `user_ids` een lijst bestaande, niet-verwijderde gebruikers.

Op `user_ids` staat `exclude_unless:is_for_everyone,false`. Dat dekt de drie
gevallen in één regel: komt de schakelaar niet mee, dan gaat deze PATCH niet
over de doelgroep; staat hij op iedereen, dan is de lijst betekenisloos en
verdwijnt hij uit de gevalideerde gegevens; staat hij uit, dan moet er iemand in
staan. Met `sometimes` zou die laatste eis juist overgeslagen worden in het
enige geval waarin hij iets betekent — een lege lijst — en met een kaal
`required_if` zou een lege lijst bij "aan iedereen" op `min:1` stuklopen.

`expires_on` mag bij het aanmaken niet in het verleden liggen (`after_or_equal:today`,
want "tot en met" laat vandaag toe) en bij het wijzigen wél: een datum in het
verleden zetten is hoe je een aankondiging vroegtijdig sluit zonder de
bevestigingen weg te gooien die verwijderen wel kost.

De veldnamen staan vertaald in `lang/nl/validation.php`, zodat een foutmelding
"zichtbaar tot en met" zegt en niet `expires_on`.

### Controller en routes

`InternalAnnouncementController` met `index`, `show`, `store`, `update`,
`destroy` en `acknowledge`.

```php
Route::resource('internalannouncements', InternalAnnouncementController::class)
    ->except(['create', 'edit']);
Route::post('internalannouncements/{internalannouncement}/acknowledge', …)
    ->name('internalannouncements.acknowledge');
```

`index` toont de aankondigingen met per stuk hoeveel ontvangers er zijn en
hoeveel er bevestigd hebben. `show` toont dezelfde aankondiging met de namen
erbij, de bevestigingsmomenten en de tijdlijn.

De gebruikerslijst voor de doelgroepkeuze reist alleen mee met wie die keuze mag
maken — `create` op de index, `update` op de detailpagina. Wie een aankondiging
enkel mag inzien heeft aan een namenlijst niets.

`store`, `update`, `destroy` en `acknowledge` draaien in een transactie. Een
aankondiging zonder ontvangers is een half aangemaakt record, en signalen vuren
binnen de transactie die ze veroorzaakt, zodat een terugdraaiing hun logregel
meeneemt.

### De gedeelde prop

`HandleInertiaRequests::share()` krijgt er `pendingAnnouncement` bij: de oudste
openstaande, nog niet bevestigde aankondiging voor de ingelogde gebruiker, of
`null`. Alleen `id`, `title` en `body` — de rest van het record gaat niemand aan
die het moet lezen.

Die naam is met opzet lang. Een paginaprop overschrijft een gedeelde prop met
dezelfde sleutel, en `announcement` is precies hoe de detailpagina zijn eigen
record noemt. Onder die naam las de balk op `/internalannouncements/{id}` de
aankondiging van de pagina in plaats van de gedeelde, en ging hij daar dus nooit
meer weg, hoe vaak je ook op Begrepen drukte.

Dit hoort in `share()` en niet in een aparte poll-route: elke Inertia-navigatie
ververst hem al, en na een bevestiging stuurt de controller terug naar dezelfde
pagina, waarna de prop vanzelf de volgende openstaande aankondiging bevat.

Als closure, niet als waarde. Inertia lost een closure pas op wanneer er echt
een pagina getekend wordt, en elk formulier dat opslaat eindigt in een redirect
die daar niet voor hoeft te betalen.

## Voorkant

### De balk

`Components/InternalAnnouncementBar.vue`, één keer gemonteerd in
`MainLayout.vue`, alleen voor een ingelogde gebruiker.

Een vaste container onder in beeld met een hogere `z-index` dan
`GlobalNotification` (die staat op `z-[9999]`), zodat een flitsbericht er niet
overheen valt. Op een telefoon staat hij boven de `MobileTabBar`, niet erachter.

De omhulling loopt over de volle breedte — de ruimte naast het menu is padding
en geen marge — en ligt door die `z-index` boven de zijbalk. Daarom
`pointer-events-none` op de omhulling en `pointer-events-auto` op de kaart, net
als bij `GlobalNotification`: anders vangt het doorzichtige stuk links de
klikken op Uitloggen op.
Megafoon-icoon, titel, bericht, en één knop: **Begrepen**, die met Inertia post
naar de bevestigingsroute.

De kaart is `bg-lavoro-green` met `text-gray-900`, zoals overal waar die kleur
in de applicatie een vlak vult. Dat dwingt twee dingen af: de schijf achter het
icoon wordt `bg-gray-900/10` in plaats van wit, dat op limoen onzichtbaar is, en
de knop wordt donkergrijs met witte letters, want wit op limoen leest niet.

De balk verdwijnt niet vanzelf en heeft geen sluitkruis. Dat is het hele punt:
een aankondiging die je kunt wegklikken zonder te bevestigen is een melding.

### Pagina's

`Pages/InternalAnnouncements/IndexPage.vue` — de lijst, met per aankondiging de
titel, de doelgroep, de voortgang ("12 van de 20 bevestigd") en of hij verlopen
is. Nieuw aanmaken gebeurt in een `DrawerComponent`.

`Pages/InternalAnnouncements/ShowPage.vue` — titel, bericht en einddatum in
`EditableTextField`s die per veld opslaan; een doelgroepsectie met een
schakelaar voor "iedereen" en daaronder een `ComboBox` in `multiple`-stand; een
lijst van ontvangers met per persoon wanneer die bevestigde of dat het nog niet
gebeurd is; en de tijdlijn.

De doelgroep slaat als paar op, met een eigen knop, en niet per gekozen naam:
halverwege een keuze zou anders een lege lijst verstuurd worden. Of er iets te
bewaren valt komt uit één vingerafdruk van de doelgroep, die ook bepaalt wanneer
het keuzeveld zich weer naar de server richt. Na het opslaan richt het zich hoe
dan ook: wie al bevestigde blijft ontvanger, dus wat je terugkrijgt is niet
altijd wat je stuurde.

De sectie is er alleen voor wie de aankondiging mag wijzigen. Zonder de
namenlijst is een keuzeveld een leeg vak, en wie hem enkel inziet leest de
doelgroep al voluit bij de bevestigingen.

Tekst en kleur van het "loopt / verlopen"-label komen uit één helper in
`Utilities.js`, zodat de lijst en de detailpagina niet uit elkaar kunnen lopen.

### Menu en icoon

`Megaphone` erbij in `Navigation/icons.js`, en een item "Aankondigingen" onder
`communicatie` in `menu.json` met `permission: internalannouncement.read`.

`MenuRow.vue` tekent alleen op diepte 0 een icoon, dus in de menuregel zelf
blijft de megafoon onzichtbaar. Elders niet: `useMenu().destinations` loopt ook
door de kinderen heen, dus de zoekbalk toont het item mét icoon. En de balk
onderin, de lege lijst en de kop op de detailpagina dragen hem ook.

## Handleiding

`docs/handleiding.md` krijgt een hoofdstuk **Aankondigingen** na "Tijdlijn en
meldingen": wat een aankondiging is, hoe je er een maakt, wat het verschil met
een melding is, en waar je terugziet wie bevestigd heeft.

## Wat er niet in zit

- Geen concept-stand. Opslaan is versturen.
- Geen soorten of kleuren (info, waarschuwing). Eén megafoon volstaat tot er een
  vraag is die dat niet aankan.
- Geen herinneringen per e-mail of pushbericht aan wie niet bevestigde. De balk
  staat er tot hij bevestigd is; dat is de herinnering.
