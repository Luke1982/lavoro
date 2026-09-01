# Waar dit stuk breekt, en hoe je dat merkt

Een risicolijst voor de omschakeling naar multi-tenancy: welke plekken fout
kunnen gaan, hoe die fout zich voordoet, en waarmee je hem vangt.

Gesorteerd op wat het kost als het misgaat, niet op hoe waarschijnlijk het is.
Bediening staat in [tenancy-bediening.md](tenancy-bediening.md).

**Wat deze lijst stuurt:** vrijwel alles hieronder faalt *stil*. Een 500 vindt
iedereen binnen een dag. Een werkbon die in de database van de verkeerde klant
belandt vindt niemand, en dat is precies wat er bij deze omschakeling mis kan
gaan. Elke regel hieronder is daarom geschreven als "hoe zou dit er uitzien als
het al kapot was".

Stand van de suite: **824 tests groen**. Waar hieronder "niet gedekt" staat is
dat gecontroleerd, niet gegokt.

---

## 1. Isolatie tussen klanten — het hele punt

**Gedekt** door `tests/Feature/Tenancy/IsolationTest.php`: twee klanten naast
elkaar, en per keer eerst bewijzen dat het gegeven er in de ene wél is voordat
er wordt gekeken of het in de andere ontbreekt. Gegevens, hetzelfde id in
allebei, aparte databases, aparte cache-aanhef, aparte bestandsmappen, een
e-mailadres dat maar bij één klant kan horen, en het spoor dat bij zijn eigen
klant blijft.

Wat het nog niet dekt: bestanden opvragen over http als de verkeerde klant
(punt hieronder), en de mailer die tussen twee klanten op één worker blijft
hangen (punt 4).

| Wat | Hoe het misgaat | Hoe je het vangt |
| --- | --- | --- |
| Query's | Een model zonder `$connection` dat toch centraal hoort, of andersom | Twee tenants, elk eigen data, tel over en weer |
| Bestanden | `/files/images/7` van klant A opvraagbaar als klant B | Vraag een id op dat van de ander is; hoort 404 te zijn. **Nog niet gedekt** |
| Zoeken | De spotlight zoekt over de verkeerde database | Zoek als B naar een naam die alleen A heeft |
| Activiteiten | Het spoor van A verschijnt in de historie van B | Wijzig iets als A, tel `activities` in beide |

`Tests\Concerns\UsesASecondTenant` zet die tweede klant op. Let op: de tweede
database draait niet in een transactie, want van tenant wisselen gooit de
verbinding weg en daarmee de transactie. Hij wordt daarom leeggemaakt aan het
begin van elke test die hem gebruikt.

## 2. Stil weggelaten velden

Twee keer geraakt op één dag, allebei onzichtbaar:

- `is_admin` bestaat niet als kolom. Elke nieuwe klant kreeg een eerste
  beheerder **zonder enig recht**. Het formulier meldde succes.
- `seat_type` stond niet in `User::$fillable`. Het formulier vroeg erom, de
  validatie keurde het goed, `create()` gooide het weg. Iedereen werd
  binnendienst en de buitendienstplekken raakten dus nooit vol — de hele
  stoelentelling stond los van de werkelijkheid.

**Gedekt** door `SilentlyDroppedAttributesTest`, die elk gevalideerd veld van
het gebruikersformulier langs `$fillable` legt. Dezelfde controle ontbreekt nog
voor de andere formulieren.

## 3. Geld

Fouten hier zijn zichtbaar bij de klant en kosten een creditnota.

| Wat | Hoe het misgaat | Status |
| --- | --- | --- |
| Dubbele factuur | Twee keer dezelfde maand; nummers uit een doorlopende reeks zijn niet te hergebruiken | Gedekt |
| Lege factuur | Een nummer zonder regels | Gedekt |
| Tussentijdse factuur | Bijkoop factureren zet het hele abonnement er nog eens bij | Gedekt |
| Optelling | De uitgesplitste regels tellen niet op tot het maandbedrag | Gedekt |
| Verrekening pakketwissel | Dagen verkeerd om, of over de verkeerde periode | **Niet gedekt** |
| Jaarkorting | Rekent mee over eenmalige posten | Deels |
| Nummerreeks | Twee facturen tegelijk krijgen hetzelfde nummer | **Niet gedekt** — race, niet af te dwingen met een test alleen; er is wel een unieke index op `number` |
| Kortingsbon | Loopt niet af, of stapelt met de vaste korting | Gedekt |

De nummerreeks is de enige waar een test niet volstaat: `MAX(nummer) + 1`
binnen een transactie zonder unieke index op `number` is een race. Er *is* een
unieke index — controleer bij de omschakeling dat die er op productie ook staat.

## 4. Post

De regel is: per klant of niets. Geen terugval op de `.env`.

- **Verkeerde afzender.** De ergste variant, want er komt geen foutmelding —
  de mail komt gewoon van het verkeerde bedrijf. Gedekt voor het weigeren
  (`IntegrationCredentialsTest`), **niet gedekt** voor de afzender die
  `ApplyTenantSender` erop zet.
- **Mailer blijft hangen tussen klanten.** Eén worker die achter elkaar voor A
  en B verstuurt, gebruikt voor B de verbinding van A. Dit is waar
  `MailerState` voor bestaat. **Niet gedekt** — en het is met een worker en
  twee tenants goed te testen.
- **Facturen aan klanten** gaan bewust via een aparte mailer. Breekt een klant
  zijn eigen mailserver, dan moeten onze facturen blijven lopen.

## 5. Achtergrondwerk

- **Job zonder tenant.** Een job die buiten een tenant is klaargezet draait
  tegen de centrale database. Handmatig gecontroleerd, **niet in de suite**.
- **Cache-vervuiling.** `PrefixCacheBootstrapper` is het enige dat de cache van
  klanten scheidt, en er zit een SnelStart-token in die cache. Een fout hier is
  geen cache-misser maar het token van een ander bedrijf. **Niet gedekt.**
- **Planner.** Draait per klant; één klant die klapt mag de rest van de ronde
  niet stoppen.

## 6. Aanmaken en verwijderen van klanten

Nieuw, en onomkeerbaar in één richting.

- **Verwijderen raakt het verkeerde bedrijf.** Daarom moet de naam letterlijk
  overgetikt worden. Controleer dat een bijna-goede naam wordt geweigerd.
- **Halve tenant.** Klapt het aanmaken halverwege, dan blijft er een database
  zonder rij of een rij zonder database staan. `tenancy:doctor` zoekt naar die
  wezen — draai hem na elke mislukte aanvraag.
- **Worker staat stil.** Dan blijft een aanvraag hangen en lijkt het paneel
  kapot. Doctor slaat aan na een kwartier.
- **Rechten van de provisioner.** Dit account mag veel: op de proefopstelling
  `ALL PRIVILEGES ON *.*`, omdat MySQL anders geen rechten kan uitdelen op een
  nieuwe klantdatabase. `tenancy:doctor` controleert nu allebei de kanten:
  dat `lavoro_app` géén klantdatabase kan maken of weggooien, en hoe de
  provisioner zichzelf bewijst.
- **De provisioner hangt nog niet aan een Linux-gebruiker.** Op de
  proefopstelling is het een gewoon account met een wachtwoord in de `.env`, en
  dat betekent dat alles wat de `.env` leest ook databases kan weggooien. De
  scheiding is daar een afspraak, geen slot. Dit is een inrichtingsstap voor
  productie (taak 2 van het plan) en de doctor meldt hem als fout tot hij
  gedaan is.

## 7. Inloggen en sessies

- Een adres wijst de klant aan. Twee klanten met hetzelfde adres kan niet, en
  dat is afgedwongen in `user_tenant_lookups`.
- **Zonder tenant mag de `web`-guard geen gebruiker oplossen.** De
  sessie-driver schrijft `user_id` via de standaardguard en die query gaat naar
  de centrale database. Dit heeft al twee keer een witte pagina opgeleverd.
  Gedekt door `MiddlewareOrderTest` voor de volgorde, **niet** voor het gedrag.
- **Wachtwoord vergeten** zoekt de klant op via het adres. Een adres dat nergens
  bestaat mag niet verklappen dat het nergens bestaat.

## 8. Ondertekende gegevens

`APP_KEY` is één sleutel voor de hele installatie, record-id's lopen per klant
op. Alles wat versleuteld een record aanwijst moet dus ook de klant noemen,
anders is het in elke klant geldig. Gedekt voor bevestigingstokens van de
assistent; de regel geldt breder — ondertekende URL's, elk toekomstig token.

## 9. MySQL tegenover SQLite

De tests draaien op MySQL omdat ze anders niet bewijzen wat er op productie
gebeurt. Dat is geen theorie:

- `DROP TABLE` is op MySQL een impliciete commit. Een test die dat deed maakte
  de testtransactie ongeldig en sloopte 23 andere tests. Nu weg.
- MySQL herschikt de sleutels van een JSON-object. Een test die op volgorde
  vergeleek was groen op SQLite en rood op MySQL.
- Indexnamen en sleutellengtes lopen uiteen.

Productie draait **MariaDB 10.11**, de ontwikkelmachine MySQL 8. Die twee zijn
niet identiek. Dit is de grootste openstaande onbekende bij de omschakeling.

## 10. Grenzen aan het abonnement

Opslag, stoelen en onderdelen. Een fout hier houdt iemand tegen die wél betaald
heeft, of laat iemand door die dat niet deed.

- Opslagteller loopt uit de pas met de werkelijkheid — er is een nachtelijke
  hertelling, controleer dat die draait.
- Een gebruiker op non-actief geeft zijn plaats vrij.
- Wat niet is afgenomen zit ook niet in het menu, en de route erachter weigert.
- **Stoelentelling was tot vandaag stuk** (zie 2). Tel na de omschakeling per
  klant of het aantal buitendienstmensen klopt met wat er in rekening gaat.

## 11. Incasso

**Gedekt** door `SepaDirectDebitTest` en `InvoiceUblTest`: de vorm van het
bestand, de optelling, het bedragformaat (een komma maakt het onbruikbaar),
één eerste incasso per machtiging, de machtiging bij elke transactie, en een
IBAN met spaties die zonder spaties wordt weggeschreven.

- Machtiging en IBAN horen bij elkaar; een IBAN met een typefout wordt dagen
  later door de bank teruggelegd. Er is een controlegetalcheck.
- Een factuur mag niet twee keer in een bestand. **Niet gedekt** — het stempel
  `collected_at` regelt het, maar er is geen test die het afdwingt.
- Het incassant-ID komt van de bank en kan niet verzonnen worden.

---

## Wat ik als eerste zou bouwen

1. **Twee tenants in de testopzet.** Zonder dat is punt 1 onbewijsbaar en
   blijft de kern van dit project ongetest.
2. **Bestanden over en weer**: `/files/...` van een ander moet 404 geven.
3. **Mailer tussen twee klanten op één worker.**
4. **Verrekening bij pakketwissel** — het enige geldpad dat nog niet gedekt is.
5. **Een ronde op MariaDB**, niet op MySQL.
