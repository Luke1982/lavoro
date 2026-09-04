# Lavoro draaien — bediening

Alles wat je op een server doet. Voor de redenering achter de opzet:
`superpowers/plans/2026-06-09-multi-database-tenancy.md`.

**Een nieuwe server opzetten of de bestaande installatie overzetten?**
Volg [tenancy-productie.md](tenancy-productie.md) — dat is de doorlopende lijst van nul tot
draaiend. Hieronder staat het dagelijkse werk.

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
php artisan tenant:override <id> --price=14900   # vaste maandprijs in centen, --clear wist hem
```

Alles kan ook in het paneel. Een pakketwissel halverwege de maand zet
automatisch een verrekening klaar voor de volgende factuur.

## Facturen

Draait elk uur vanzelf en maakt alleen aan wat aan de beurt is:

```bash
php artisan invoices:issue --dry-run     # laat zien wat er zou komen
php artisan invoices:issue               # maakt aan, verstuurt niets
php artisan invoices:issue --mail        # maakt aan én verstuurt
```

Versturen gebeurt met opzet niet vanzelf. In het paneel staat per factuur een
knop, met de PDF en het UBL-bestand als bijlage.

Incasso: `/beheer/incasso` levert een SEPA-bestand (pain.008) voor de bank.
Vereist per klant een machtiging en IBAN, en één keer een incassant-ID onder
Catalogus → Facturatie.

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

## Verder lezen

| | |
| --- | --- |
| `tenancy-testrisicos.md` | waar dit stuk breekt en hoe je dat merkt |
| `../CLAUDE.md` | regels voor wie code schrijft |
| `handleiding.md` | voor de mensen die ermee werken |
