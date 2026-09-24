# Terugkoppeling tijdens het afmelden

Afmelden voelt kapot. Je drukt op Uitloggen en er gebeurt seconden niets, dus je
drukt nog een keer.

Het is niet kapot, het is traag, en met reden. `MainLayout.logout()` breekt eerst
alles af wat de applicatie op het apparaat heeft achtergelaten voordat het
navigeert: het stopt de plaatsbepaling, zegt het pushabonnement op bij de server,
schrijft elke service worker uit en gooit elke cache weg. Dat is werk dat
gedaan moet worden — een pushabonnement dat blijft staan bezorgt berichten aan
iemand die niet meer ingelogd is — maar er is niets op het scherm dat het zegt.

## Wat het moet doen

- Meteen bij de klik laten zien dat er iets loopt.
- Een tweede klik niet nog een keer alles laten afbreken.
- Niet laten klikken op een applicatie die haar eigen caches aan het weggooien is.

## De stand

Eén `logging_out` in `useMenu()`, naast `open_ids` en `collapsed`, dus op
moduleniveau en niet in de composable-functie. Er zijn drie uitlogknoppen — de
kaart onder in de zijbalk, het pictogram in het smalle spoor, en dezelfde kaart
in het mobiele paneel — en die horen het over één stand eens te zijn zonder dat
iemand hem doorgeeft. `collapsed` doet het al zo.

Naar buiten als `loggingOut` met een `setLoggingOut`, hetzelfde paar als
`collapsed` en `setCollapsed`.

## Wat het toont

**De knop die je indrukte.** In `MenuCards` en in het smalle spoor van
`MenuSidebar` wordt het pictogram een draaiende `LoaderCircle`, staat er "Bezig
met afmelden…" in plaats van "Uitloggen", en is de knop uitgeschakeld met
`cursor-wait`. De aanwijsstijl gaat uit, anders licht een knop op die niets meer
doet.

**Een laag over alles.** `Components/UI/LogoutOverlay.vue`: een gedimd vlak met
dezelfde draaier en dezelfde zin, op `z-[10001]` — boven de aankondigingsbalk.
Dat het klikken tegenhoudt is de bedoeling en geen bijwerking: halverwege het
opruimen valt er niets zinnigs meer aan te klikken.

Een eigen onderdeel en niet een blok in `MainLayout`, want zo staat elke andere
laag op applicatieniveau er ook: `OfflineBanner`, `UpdateBanner`,
`PushPermissionBanner`, `GlobalNotification`. Het haalt `loggingOut` zelf op, dus
de indeling hoeft er niets van te weten.

## De vlag weer uit

`logout()` zet hem aan vóór het eerste trage stuk en zet hem niet meer uit:
daarna verlaten we de pagina toch.

Dat kan niet blijven staan. De vlag leeft op moduleniveau en overleeft dus een
afmelding, terwijl de aanmeldpagina `EmptyLayout` gebruikt en `MainLayout` dus
afgebroken wordt. Zonder meer zou de volgende aanmelding achter een laag
beginnen die er nooit meer af gaat. `MainLayout` zet hem daarom leeg bij het
opbouwen: staat die indeling er weer, dan zijn we ingelogd en is er niets gaande.
