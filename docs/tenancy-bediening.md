# Lavoro draaien — bediening

Alles wat je op een server doet. Voor de redenering achter de opzet:
`superpowers/plans/2026-06-09-multi-database-tenancy.md`.

**Een nieuwe server opzetten of de bestaande installatie overzetten?**
Volg [tenancy-productie.md](tenancy-productie.md) — dat is de doorlopende lijst van nul tot
draaiend. Hieronder staat het dagelijkse werk.


## Lokaal werken

Alles hieronder valt ook lokaal te doen, zonder productie:

```bash
./scripts/tenancy/dev.sh                 # app, beide workers en vite
./scripts/tenancy/dev.sh --reset-logins  # alle wachtwoorden op 'testtest'
```

De app staat dan op http://127.0.0.1:8199, het beheerpaneel op /beheer. Het
script noemt bij het starten welke klanten er zijn en met welk e-mailadres je
bij elk binnenkomt.

Dat draait op `.env.localtest`, met een eigen centrale database en echte
klantdatabases ernaast -- niet op `.env`, want die wijst naar een database die
niet altijd draait. Vandaar dat het script `APP_ENV` als omgevingsvariabele
zet: `--env=localtest` geldt alleen voor het artisan-commando zelf, terwijl de
verzoeken die de server afhandelt opnieuw opstarten en dan gewoon `.env` lezen.

## Wat er moet draaien

| | Wat | Als wie |
| --- | --- | --- |
| Webserver | php-fpm / lsphp | het account van de webserver |
| Worker | `php artisan queue:work` | het account van de installatie |
| Worker | `php artisan queue:work --queue=provisioning` | `lavoro_provisioner` |
| Cron | `* * * * * php artisan schedule:run` | het account van de installatie |

Er staat met opzet geen naam bij die eerste twee. Welk account de webserver
gebruikt verschilt per server -- `www-data` bij Apache en nginx, `nobody` bij
LiteSpeed -- en dat hoeft niet hetzelfde te zijn als het account waar de
bestanden van zijn. Aannemen dat het `www-data` is kostte een hele dag: de
webserver kon niet in `storage/logs` schrijven, dus verdween elke fout uit een
webverzoek spoorloos, en er was geen enkele melding om op te zoeken.

Uitzoeken hoeveel het er zijn:

```bash
ps -eo user,comm | grep -iE 'lsphp|php-fpm'
stat -c %U artisan
```

De doctor leest het zelf af aan de gecompileerde sjablonen -- die schrijft de
webserver -- en controleert of dat account bij het logboek en bij de mappen van
elke klant kan. `scripts/tenancy/setup-workers.sh` schrijft de systemd-units met
de accounts en paden die op deze server gelden.

Twee workers, en dat is met opzet. De gewone draait als het account van de
applicatie, dat geen databases mag aanmaken. Alleen de tweede mag dat: kon het
paneel zelf databases maken, dan kon een fout in het paneel er ook een
weggooien.

De gewone worker pakt de wachtrij `provisioning` niet op, en dat hoort zo: hij
zou er toch op stuklopen. Draait de tweede niet, dan blijft een aanvraag in het
paneel op "in de wacht" staan en meldt `tenancy:doctor` het na een kwartier.

`--tries=1` op die tweede is geen slordigheid: een half aangemaakte klant nog
eens proberen loopt vast op "de database bestaat al" en verbergt de echte fout.
De units schrijft `scripts/tenancy/setup-workers.sh`; die leest het account, het
pad, de php-binary en de naam van de databaseservice van de machine af.

Staat de cron niet, dan gebeurt er niets automatisch: geen facturen, geen
Google-synchronisatie, geen werkbonnen uit onderhoudscontracten.
`php artisan tenancy:doctor` merkt het binnen een kwartier.

## Controleren of het klopt

```bash
php artisan tenancy:doctor
```

Op een ontwikkelmachine hoort de testsuite te draaien voordat er iets naar een
server gaat:

```bash
sudo scripts/tenancy/setup-test-db.sh    # eenmalig
composer test
```

Dat script zet de testdatabase klaar met dezelfde grant-procedure als in
productie, zodat de tests dezelfde weg lopen. Een testomgeving die het net even
anders doet, test het verkeerde. `IsolationTest` maakt twee echte klanten aan en
komt daarmee langs het aanmaken, de rechten en de scheiding tussen klanten.

Loopt elke tenant af en controleert onder andere: staan de tabellen er, draait
de cron, kan het applicatieaccount géén klantdatabases weggooien, bestaat het
provisioner-account, staan er geen databases zonder tenant. Geeft exitcode 1
bij een probleem, dus `deploy.sh` breekt erop af.

## De drie MySQL-accounts

| Account | Mag | Waarvoor |
| --- | --- | --- |
| `lavoro_app` | alleen de landlord-database | de applicatie zelf |
| `lavoro_provisioner` | alleen `lavoro_tenant_%` | klanten aanmaken en weggooien |
| per klant één | alleen zijn eigen database | de verbinding tijdens een verzoek |

`lavoro_app` kan met opzet geen klantdatabase maken of weggooien, en de
provisioner komt met opzet nergens buiten de klantnaamruimte. Dat zijn de twee
grenzen waar de hele opzet op leunt; `verify-mysql.sh` probeert ze allebei te
overtreden en verwacht een weigering.

### Inrichten (eenmalig, per server)

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run    # laat de SQL zien, wijzigt niets
sudo scripts/tenancy/setup-mysql.sh --write-env  # doet het, en zet .env goed
```

Dat maakt de Linux-gebruiker, de accounts, de juiste rechten en de procedure
hieronder. Met de hand is het niet te doen zonder iets te breken; de volledige
installatie staat in `tenancy-productie.md`.

### Waarom een procedure de rechten uitdeelt

Elke klant krijgt een eigen MySQL-login die alleen bij zijn eigen database mag.
Dat aanmaken is het werk van de provisioner, maar MySQL en MariaDB wegen een
`GRANT` die een database bij naam noemt af tegen een rij die exact op die naam
staat, en nooit tegen het jokerteken `lavoro\_tenant\_%`. De provisioner kan
`lavoro_tenant_acme` dus wel aanmaken en er geen rechten op uitdelen: fout 1044.

De verleiding is dan om het account `ALL PRIVILEGES ON *.*` te geven. Doe dat
niet — daarmee is het net zo machtig als root en is er van de afscherming niets
meer over.

In plaats daarvan deelt de procedure `lavoro_admin.grant_tenant_access` de
rechten uit. Die staat in een eigen database, draait als degene die hem heeft aangemaakt (root) en
weigert elke naam buiten de klantnaamruimte. De provisioner heeft in die
database niets behalve het recht hem aan te roepen, dus hij kan hem niet
vervangen door een ruimere versie. Zie `scripts/tenancy/setup-mysql.sh`.

### Na elke wijziging: de workers herstarten

```bash
sudo systemctl restart lavoro-worker lavoro-provisioning
```

Hetzelfde geldt voor php onder de webserver: die houdt de gecompileerde code
vast (opcache). `view:clear` raakt dat niet aan, dus na een pull draait de
webserver door op de oude klassen terwijl de sjablonen al nieuw zijn -- een
combinatie die vreemde dingen doet, zoals een scherm dat zichzelf blijft
herladen omdat de controller een waarde nog niet meestuurt die het sjabloon al
verwacht.

```bash
sudo pkill lsphp                    # LiteSpeed: de processen komen vanzelf terug
sudo systemctl reload php8.3-fpm    # Apache of nginx met php-fpm
```

Voor lsphp bestaat geen systemd-unit; die processen worden door LiteSpeed zelf
opnieuw gestart zodra ze weg zijn.

Een worker leest `.env` en de code één keer, bij het opstarten, en houdt dat
vast. Na een `git pull` of een wijziging in `.env` draait hij dus door op wat
hij had, terwijl de hartslag gewoon blijft komen en alles er gezond uitziet.
`deploy.sh` doet dit vanzelf; een handmatige pull niet. De doctor vergelijkt
waar de worker mee is opgestart met wat er nu staat en zegt het als dat
verschilt.

## Klanten

```bash
php artisan tenants:list                                  # naam, database, gebruikers
php artisan tenant:overview                               # plaatsen, opslag, maandprijs

php artisan tenant:create "Bedrijf BV" beheer@bedrijf.nl  # klant + eerste beheerder
php artisan tenant:delete <id>                            # alles weg, vraagt bevestiging
```

Beide commando's verheffen zichzelf tot `lavoro_provisioner` als de sudo-regel
er is (`sudo scripts/tenancy/setup-sudoers.sh`, eenmalig). Is die er niet, dan
zeggen ze welk commando je moet typen.

Verwijderen kan ook in het paneel: open de klant met **bewerken** en gebruik het
rode blok onderaan. De naam moet daar letterlijk overgetikt worden, want er is
geen weg terug en geen prullenbak. Is de database van die klant al weg, dan
blijft dat scherm gewoon werken -- juist dan heb je die knop nodig.

Aanmaken kan ook in het beheerpaneel onder `/beheer`. Dat zet een aanvraag klaar die de
provisioning-worker uitvoert; blijft hij op "in de wacht" staan, dan draait die
worker niet. Loopt een aanvraag stuk, dan blijft hij met de reden erbij staan
tot je hem met **weghalen** wegklikt -- opgeruimd wordt er vanzelf, maar de
reden hoort te blijven staan tot iemand hem gezien heeft.

### Beheerder voor een bestaande klant

`tenant:create` maakt er meteen een. Een database die met
`tenant:setup-existing` is overgenomen heeft er nog geen:

```bash
php artisan tenant:admin "Bedrijf BV" beheer@bedrijf.nl
php artisan tenant:admin <id> beheer@bedrijf.nl --password=eigenwachtwoord
```

Bestaat de gebruiker al, dan zet dit zijn wachtwoord opnieuw en bevestigt het
de beheerdersrol. Het wachtwoord komt op het scherm.

### Beheerder voor het paneel zelf

```bash
php artisan landlord:user jij@majorlabel.nl
```

Dat is een los account in de landlord-database; het heeft niets te maken met de
gebruikers van een klant.

## Abonnement aanpassen

```bash
php artisan tenant:package <id> business
php artisan tenant:modules <id> --add=assistant --remove=quotes
php artisan tenant:seats <id> --field=+5 --office=2
php artisan tenant:storage <id> --limit=200
php artisan tenant:override <id> --price=14900   # vaste pakketprijs in centen, --clear wist hem
```

**Een vaste prijs is de prijs van het pakket met al zijn plekken en opslag**,
hoeveel het er ook zijn. Alleen de modules (offertes, facturen, AI-assistent)
komen erbovenop, en los gekocht AI-tegoed. Voor een losse module valt in het
paneel een eigen prijs af te spreken, en die gaat voor op een bundelprijs uit
de catalogus. Naast de vaste prijs zet de factuur wat pakket, plekken en opslag
normaal hadden gekost.

Overal waar zo'n afspraak geldt, zet de factuur de gewone prijs erbij:
*Abonnement Lavoro Starter 01-09-2026 t/m 30-09-2026, normaal € 27,50, speciale
prijsafspraak*. Over een jaar weet niemand meer waarom er een ander bedrag
stond, en de klant hoort te zien dat het een afspraak was en geen fout.

Alles kan ook in het paneel.

**Alleen een pakketwijziging wordt verrekend.** Wissel je halverwege een periode
van pakket, of spreek je een andere prijs voor dat pakket af, dan betaalt de
klant over die periode het oude tot de dag van de wissel en het nieuwe daarna.
Wat er los bijkomt levert geen aparte verrekeningsregel op. Een module die
halverwege de maand wordt aangezet, wordt op zijn eigen regel naar rato
gerekend: *AI-assistent 07-09-2026 t/m 30-09-2026 (24 van 30 dagen)*. Staat er
een prijsafspraak op, dan gaat de normale prijs ernaast over dezelfde dagen, zodat
de twee bedragen te vergelijken zijn. Een bundel telt vanaf de dag dat hij
compleet werd.

Extra plekken en opslag gaan wel voor de hele periode mee; die horen bij de
omvang van het abonnement en niet bij een los product.

**Opzeggen** gaat met de datum *Opgezegd per*: de laatste dag waarop het
abonnement loopt. Er wordt tot en met die dag gerekend, dus de laatste maand
staat naar rato op de factuur. Was die maand al gefactureerd, dan komt het te
veel betaalde als tegoed terug op de eerstvolgende factuur. Wordt de opzegging
weer ingetrokken, dan vervalt dat tegoed. Na de laatste dag valt er niets meer
te factureren.

Blijft er tegoed over waar niets meer tegenover staat -- en bij een vertrokken
klant is dat de regel, want er komt geen volgende factuur meer -- dan levert de
knop een **creditfactuur** op: hetzelfde nummer uit dezelfde reeks, met een
negatief bedrag. De pdf en de mail heten dan ook creditfactuur. Incasseren kan
er niet mee: terugstorten gaat met de hand, en het incassobestand slaat
creditfacturen over.

**Van maand naar jaar of andersom** begint de nieuwe termijn bij de
eerstvolgende periode die nog niet betaald is, en nooit met terugwerkende
kracht. Wie in september zijn maand al betaald heeft en overstapt op jaar,
krijgt zijn jaarfactuur per 1 oktober; wie een jaar vooruitbetaald heeft en
naar maand gaat, krijgt zijn eerste maandfactuur zodra dat jaar op is. Dat
anker staat los van de ingangsdatum op het scherm, die blijft wat hij was.

De verrekening van een pakketwijziging kan twee kanten op:

- Was de periode al gefactureerd tegen de oude prijs, dan komt het verschil er
  bij over de dagen die nog komen.
- Was hij nog niet gefactureerd, dan rekent de eerstvolgende factuur het nieuwe
  pakket over de hele periode -- ook over de dagen op het oude pakket. Die gaan
  er als tegoed af.

Een wissel op de eerste dag van een nog niet gefactureerde periode levert dus
niets op: er is dan nog geen dag op het oude pakket voorbij.

**Wat er vóór de ingangsdatum wordt afgesproken, is de afspraak zelf** en wordt
nooit verrekend. Schuif je de ingangsdatum op tot voorbij een wijziging, dan
vervalt de verrekening die die wijziging opleverde bij het opslaan: het waren
dagen waarop er nog niets liep. De periodes tellen ook nooit vanaf vóór de
ingangsdatum, ook niet als een termijnwissel eerder een anker achterliet.

Een openstaande verrekening die niet klopt, haal je weg met *verwijderen* naast
de regel onder Facturatie.

Gaat een klant naar een goedkoper pakket, dan staat er tegoed open. Is dat meer
dan er op dat moment te factureren valt, dan komt er geen factuur: het tegoed
blijft staan en gaat van de volgende af. Het scherm zegt dat er dan ook bij.

## Facturen

Draait elk uur vanzelf en maakt alleen aan wat aan de beurt is:

```bash
php artisan invoices:issue --dry-run     # laat zien wat er zou komen
php artisan invoices:issue               # maakt aan, verstuurt niets
php artisan invoices:issue --mail        # maakt aan én verstuurt
```

Versturen gebeurt met opzet niet vanzelf. In het paneel staat per factuur een
knop, met de PDF en het UBL-bestand als bijlage.

Vóór de ingangsdatum van een klant maakt de ronde niets aan, ook niet als er al
iets openstaat: dat gaat mee op de eerste factuur. Per klant staat in het
abonnement een vinkje *Automatisch factureren*; uit betekent dat de ronde de
klant overslaat en je de factuur zelf aanmaakt bij *Facturen bekijken*. Het
abonnementsblok en de facturatiekaart laten regel voor regel zien wat er
gefactureerd wordt en waarom.

**Een gat van vóór deze regel** -- facturen die eerder verwijderd zijn en hun
nummer hielden -- sluit je onder Catalogus → Facturatie met *terugzetten*, als het
gat aan het eind van het jaar zit.

**Een jaar opnieuw beginnen** is de laatste uitweg, voor een jaar vol proefdraaien:
onderaan Catalogus verwijdert het alle facturen van dit jaar, creditfacturen en
verstuurde meegerekend, en de volgende factuur wordt weer nummer 1. Je tikt het
jaartal over om te bevestigen. Wat ze verrekenden staat weer open, en het
automatisch factureren maakt de lopende periode daarna opnieuw aan. Het scherm
zegt hoeveel er al gemaild, geïncasseerd of naar e-Boekhouden gestuurd is: die
zet je daar zelf recht. Elk verwijderd nummer komt in de log. Een boeking in
e-Boekhouden met een nummer dat terugkomt, wordt bij een andere relatie
geweigerd in plaats van voor de nieuwe factuur aangezien; haal hem daar weg.

Incasso: `/beheer/incasso` levert een SEPA-bestand (pain.008) voor de bank.
Vereist per klant een machtiging en IBAN, en één keer een incassant-ID onder
Catalogus → Facturatie.

**De nummering heeft geen gaten, en een factuur in de boeken verandert niet.**
Verwijderen kan daarom alleen bij de nieuwste factuur van het hele systeem, over
alle klanten heen, en alleen zolang niemand hem heeft: niet gemaild, geïncasseerd
of naar e-Boekhouden gestuurd (ook een mislukte boeking telt: of die aankwam is
niet zeker). Het nummer gaat dan terug naar de volgende factuur. Die ene factuur
crediteer je niet; dat kost twee nummers voor niets.

**Elke andere foute factuur** -- de deur uit, of er is daarna nog een factuur
gemaakt -- gaat met *crediteren*: dat maakt een
creditfactuur met een eigen nummer die alle bedragen terugdraait en de
oorspronkelijke factuur noemt, op de pdf en in de UBL. De periode staat daarna
weer open; wat de foute factuur verrekende (bijgekocht AI-tegoed, een
verrekening) staat weer klaar voor de nieuwe. Verrekeningen die ná de foute
factuur over dezelfde periode zijn gemaakt, gingen ervan uit dat die betaald
was: een opzegging vervalt, een pakketwissel wordt opnieuw uitgerekend over de
dagen op het oude pakket, en wat een latere factuur daarvan al rekende wordt op
de volgende teruggedraaid. Is het de periode van vandaag, dan maakt *Factuur
aanmaken* de juiste; een oudere periode staat bovenaan *Facturen bekijken* bij de
niet gefactureerde periodes, met een knop *factureren* (gedateerd vandaag). Een
factuur wordt één keer gecrediteerd, een creditfactuur nooit, en geen van beide
kan daarna nog weg.

**De nummering van dit jaar terugzetten** kan onder Catalogus → Facturatie. De
teller gaat terug naar het hoogste nummer dat nog op een factuur staat, zodat
de nummers van verwijderde facturen opnieuw uitgegeven worden. Lager kan niet,
en alleen verwijderde facturen maken een nummer vrij -- die zijn nooit gemaild,
geïncasseerd of geboekt. Heb je er een als pdf met de hand verstuurd, zet dan
niet terug. Wie het deed en van welk nummer naar welk, staat in het log.

**e-Boekhouden.** Met `EBOEKHOUDEN_USERNAME`, `EBOEKHOUDEN_SECURITY_CODE_1` en
`EBOEKHOUDEN_SECURITY_CODE_2` in `.env` (de SOAP-koppeling van e-Boekhouden)
verschijnt er een kaart op het abonnementsscherm en een knop *naar
e-Boekhouden* per factuur. Geboekt wordt als verstuurde factuur op
`EBOEKHOUDEN_DEBTORS_ACCOUNT` (1300) tegen `EBOEKHOUDEN_REVENUE_ACCOUNT` (8000),
per regel, met de jaarkorting als eigen regel en de btw per regel zo afgerond
dat het totaal op de cent gelijk is aan de factuur. Staat het factuurnummer er
al, dan wordt het niet nog eens geboekt.

Boeken kan pas als de klant aan een relatie gekoppeld is. Zoek op de kaart of
hij er al staat en klik op de juiste relatie; klik op de gekoppelde om hem los
te maken. *Nieuwe relatie aanmaken* weigert zolang er een relatie is met
hetzelfde KvK-nummer, btw-nummer, factuur-e-mailadres of dezelfde naam (zonder
B.V. en leestekens); de nieuwe krijgt de code `LVR-` plus het begin van het
klant-id. Een geboekte factuur kan niet meer weg, alleen gecrediteerd worden.

## Migraties

```bash
php artisan migrate                # alleen de landlord-database
php artisan tenants:migrate        # elke klant
```

Nieuwe migraties horen in `database/migrations/tenant/`, tenzij ze
`protected $connection = 'central'` zetten. Vergeet je `tenants:migrate`, dan
blijft elke klant achter en breekt de applicatie pas bij het eerste verzoek dat
de nieuwe kolom aanraakt. `deploy.sh` doet allebei.

## Uitrollen

```bash
./scripts/tenancy/deploy.sh
```

Onderhoudspagina aan, code ophalen, beide migraties, caches, workers
herstarten, doctor, onderhoudspagina uit. Breekt af zodra iets misgaat, en de
onderhoudspagina gaat dan alsnog uit.

## Een bestaande installatie overnemen

```bash
./scripts/tenancy/import-install.sh
```

Zet een dump van een losse installatie om in een klant binnen deze opzet. Zie
taak 44 van het plan voor wat het precies doet.

Staat de oude installatie op een andere server, neem dan de dump, de `.env` en
een archief van `storage` mee en gebruik:

```bash
./scripts/tenancy/import-archive.sh --dump oud.sql.gz --env oud.env --storage storage.zip \
    --name "Bedrijf BV" --slug bedrijf --super-admin info@majorlabel.nl=info+bedrijf@majorlabel.nl
```

Na de migraties brengt `tenant:adapt-import` de klant gelijk met een nieuwe: de
fases en rollen die een nieuwe klant krijgt, een fase voor open werkbonnen van
vóór de fases, wie ingepland kan worden, en de rechten die de oude installatie nog
niet kende. Locatie volgen wordt nooit zo toegekend, dat beslist de klant zelf.
Ons eigen account uit de oude installatie wordt superbeheerder. Alles in
`install/import-existing.md`.

## Verder lezen

| | |
| --- | --- |
| `tenancy-testrisicos.md` | waar dit stuk breekt en hoe je dat merkt |
| `../CLAUDE.md` | regels voor wie code schrijft |
| `handleiding.md` | voor de mensen die ermee werken |
