# Databasefouten worden meldingen

Iemand voerde een merk in dat al bestond. De index `brands_name_unique` deed
precies waarvoor hij er ligt, MySQL gaf 1062 terug, en de gebruiker kreeg een
500 in het gezicht — plus zeventig regels stacktrace in het logboek.

Dat is geen incident maar een patroon. Het schema legt 44 unieke indexen op,
en zeventien van de 233 form requests zetten er een `unique`-regel tegenover —
de foreign keys en de NOT NULL-kolommen nog niet meegerekend. Elke regel die de
database wel bewaakt en het formulier niet, is een 500 die op zijn beurt wacht.
Validatie vooraf is bovendien niet waterdicht: twee mensen die tegelijk
hetzelfde merk aanmaken komen allebei door de `unique`-regel heen, en één van
de twee komt alsnog bij de index uit.

Het vangnet hoort dus onder de hele applicatie te liggen en niet onder één
formulier.

## Wat het moet doen

- Een geschonden databaseregel eindigt in een rode melding en niet in een 500.
  In elke omgeving: lokaal is de melding net zo goed het gedrag dat klopt.
- Hoort de fout bij een veld, dan staat de melding bij dat veld. Het formulier
  blijft open met alles wat er al ingevuld stond.
- Verwijderen dat geweigerd wordt omdat er nog iets aan hangt, zegt dat.
- Aanroepen die JSON verwachten — axios, de API-routes — krijgen dezelfde fout
  als een 422, in de vorm die Laravel-validatie ook teruggeeft.
- Wat niet herkend wordt blijft een echte fout. Een onbegrepen databasefout
  wegmoffelen is erger dan een 500.

## Waar het hoort

In `bootstrap/app.php`, als `render`-regel voor `QueryException`, naast de
regels die er al staan voor `AuthorizationException`, 403 en 419. Dat is de
enige plek die alles ziet: de fout kan uit een controller komen, uit een
service, uit een observer of uit een model-event, en op elk van die plekken zou
een `try`/`catch` het probleem één keer oplossen en de volgende keer weer niet.

`render` en niet de `respond`-regel die er al staat voor 500'en: die is bewust
alleen buiten development actief en heeft geen idee meer wat er misging — hij
kan niets beters zeggen dan "er is een serverfout opgetreden". De vertaling
hoort bij de fout, niet bij de statuscode.

De vertaling zelf staat los in `App\Support\DatabaseErrorMessage`, omdat
`bootstrap/app.php` een bedradingsbestand is en geen plek voor reguliere
expressies.

## Van foutmelding naar veld

Een melding is pas een goede melding als hij het veld noemt. De driver noemt
dat veld alleen niet: MySQL kent bij een dubbele waarde alleen de índex
(`brands_name_unique`) en niet de kolommen die erin zitten. Die worden
teruggerekend:

1. Neem de indexnaam uit de melding, zonder achtervoegsel `_unique` en zonder
   de tabelnaam ervoor: `brands_name_unique` wordt `name`.
2. Pel wat overblijft woord voor woord af tegen de kolommen die in de mislukte
   query staan, langste eerst. `serial_number_product_id` wordt zo
   `serial_number` + `product_id` en niet `serial_number` + `product` + `id`.
3. Past het niet precies, dan levert het niets op. Bij een index met een
   zelfverzonnen korte naam (`gse_cal_event_unique`) valt niet te raden welke
   kolommen erin zitten, en een half geraden veldnaam is erger dan geen.

Er wordt gelezen uit de melding van de dríver, niet uit die van de
`QueryException`: die laatste plakt de ingevulde waarden achter de tekst, en
daarin kan alles staan wat een gebruiker maar intypt — ook iets dat op een
indexnaam lijkt.

SQLite noemt de kolommen wel meteen (`UNIQUE constraint failed: brands.name`),
dus daar is stap 1 tot en met 3 niet nodig. Dat het allebei werkt is geen
franje: de testsuite draait op SQLite en productie op MySQL.

Wel antwoordt SQLite met één foutnummer (19) voor elke geschonden regel, en
zegt het in woorden welke het was. Die woorden worden eenmalig teruggelezen
naar het MySQL-nummer dat erbij hoort, zodat de rest van de vertaling maar één
woordenschat kent. Alleen bij een foreign key valt daar iets te kiezen: MySQL
zegt of de rij de verwijzing hield (1451) of hem legde (1452) en SQLite niet.
Daar beslist het soort query, want een `delete` kan alleen zijn stukgelopen op
de rij waar anderen naar wijzen.

## De teksten

| Wat er misging | Melding |
|---|---|
| Dubbele waarde, één kolom | `Naam is al in gebruik.` |
| Dubbele waarde, meerdere kolommen | `Deze combinatie van serienummer en product bestaat al.` |
| Dubbele waarde, kolom onbekend | `Er bestaat al een item met deze gegevens.` |
| Verwijderen geweigerd (1451) | `Dit item wordt nog ergens gebruikt en kan daarom niet verwijderd worden.` |
| Verwijzing bestaat niet (1452) | `Merk bestaat niet (meer).` |
| Verplichte kolom leeg (1048, 1364) | `Product is verplicht.` |
| Waarde te lang (1406) | `Naam is te lang.` |
| Waarde buiten bereik (1264) | `Prijs is buiten het toegestane bereik.` |
| Waarde van de verkeerde soort (1265, 1292, 1366) | `Startdatum heeft een ongeldige waarde.` |

De veldnaam komt uit `lang/nl/validation.php` onder `attributes`, dezelfde
lijst waar de gewone validatiemeldingen uit putten. Staat een kolom er niet in,
dan wordt de kolomnaam zelf gebruikt met de streepjes eruit en zonder `_id`.
Kolommen die in deze meldingen opduiken en nog geen vertaling hadden, krijgen
die alsnog.

De formulering is bewust dezelfde als die van de `unique`-regel (":Attribute is
al in gebruik."): of de melding nu van de validatie komt of van de index, de
gebruiker hoort hetzelfde te lezen.

## Wat het bewust niet doet

**De waarde niet teruggeven.** "Er bestaat al een item met de waarde 'WAllbox'"
is behulpzaam tot de dubbele waarde een token, een uuid of een hash is die de
gebruiker nooit zelf intypte. Het veld noemen is bijna altijd genoeg, en in de
gevallen waarin dat niet lukt is een vage melding beter dan een lek.

**Niet alsnog overal `unique`-regels zetten.** Het vangnet geeft exact dezelfde
melding op exact hetzelfde veld als een `unique`-regel zou doen. Wat een regel
vooraf extra oplevert is een query minder en een logregel minder — niet een
betere melding. Waar zo'n regel voor de hand ligt, hoort hij er; hij is alleen
geen voorwaarde meer om dit gedrag te krijgen.

**Blijven loggen.** Elke geschonden regel blijft in het logboek staan. Een
dubbele merknaam is ruis, maar een foreign key die niet meer klopt is een bug,
en die twee zijn van tevoren niet uit elkaar te houden.

**Geen tabelnamen in "nog in gebruik".** MySQL noemt bij 1451 de tabel die de
verwijzing houdt (`products`), maar dat is een technische naam en geen Nederlands
woord dat een gebruiker kent. Het vertalen van tabelnamen is een lijst die
stilletjes veroudert.

**Alleen bij schrijvende verzoeken.** Een GET die door de database geweigerd
wordt is een fout in de route en niet van de gebruiker; die houdt zijn 500. Het
scheelt bovendien een redirect-lus: een paginabezoek terugsturen naar waar het
vandaan kwam is precies hoe een herlaadpoging in een kringetje gaat lopen.

**Alleen HTTP.** Een commando of een queue-job die op dezelfde index stuk loopt,
loopt gewoon stuk. Daar zit geen gebruiker naar te kijken.

## Wat er verder meegaat

- `BrandController::store/update` krijgt de `unique`-regel die er hoorde te
  staan, zodat het gemelde geval al bij de validatie stopt.
- `docs/handleiding.md` krijgt onder de vaste gewoontes een alinea over wat je
  ziet als opslaan of verwijderen niet lukt.
