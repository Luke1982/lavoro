# Lavoro draaien — bediening

Alles wat je op een server doet. Voor de redenering achter de opzet:
`superpowers/plans/2026-06-09-multi-database-tenancy.md`.

**Een nieuwe server opzetten of de bestaande installatie overzetten?**
Volg [tenancy-productie.md](tenancy-productie.md) — dat is de doorlopende lijst van nul tot
draaiend. Hieronder staat het dagelijkse werk.

## Wat er moet draaien

| | Wat | Als wie |
| --- | --- | --- |
| Webserver | php-fpm / nginx | `www-data` |
| Worker | `php artisan queue:work` | `www-data` |
| Worker | `php artisan queue:work --queue=provisioning` | `lavoro_provisioner` |
| Cron | `* * * * * php artisan schedule:run` | `www-data` |

Twee workers, en dat is met opzet. De gewone draait als het account van de
applicatie, dat geen databases mag aanmaken. Alleen de tweede mag dat: kon het
paneel zelf databases maken, dan kon een fout in het paneel er ook een
weggooien.

De gewone worker pakt de wachtrij `provisioning` niet op, en dat hoort zo: hij
zou er toch op stuklopen. Draait de tweede niet, dan blijft een aanvraag in het
paneel op "in de wacht" staan en meldt `tenancy:doctor` het na een kwartier.

`--tries=1` op die tweede is geen slordigheid: een half aangemaakte klant nog
eens proberen loopt vast op "de database bestaat al" en verbergt de echte fout.
De systemd-unit staat in [tenancy-productie.md](tenancy-productie.md).

Staat de cron niet, dan gebeurt er niets automatisch: geen facturen, geen
Google-synchronisatie, geen werkbonnen uit onderhoudscontracten.
`php artisan tenancy:doctor` merkt het binnen een kwartier.

## Controleren of het klopt

```bash
php artisan tenancy:doctor
```

Loopt elke tenant af en controleert onder andere: staan de tabellen er, draait
de cron, kan het applicatieaccount géén klantdatabases weggooien, bestaat het
provisioner-account, staan er geen databases zonder tenant. Geeft exitcode 1
bij een probleem, dus `deploy.sh` breekt erop af.

## De drie MySQL-accounts

| Account | Mag | Waarvoor |
| --- | --- | --- |
| `lavoro_app` | alleen de landlord-database | de applicatie zelf |
| `lavoro_provisioner` | alles, mét rechten uitdelen | klanten aanmaken en weggooien |
| per klant één | alleen zijn eigen database | de verbinding tijdens een verzoek |

`lavoro_app` kan met opzet geen klantdatabase maken of weggooien. Dat is de
grens waar de hele opzet op leunt; de doctor controleert hem.

### De provisioner inrichten (eenmalig, per server)

```bash
sudo adduser --system --group --no-create-home lavoro_provisioner
```

```sql
CREATE USER 'lavoro_provisioner'@'localhost' IDENTIFIED WITH auth_socket;
GRANT ALL PRIVILEGES ON *.* TO 'lavoro_provisioner'@'localhost' WITH GRANT OPTION;
```

`auth_socket` betekent: geen wachtwoord, en alleen de Linux-gebruiker met
dezelfde naam kan inloggen. Zet daarna in `.env`:

```
DB_PROVISIONER_USERNAME=lavoro_provisioner
DB_PROVISIONER_SOCKET=/var/run/mysqld/mysqld.sock
```

en haal `DB_PROVISIONER_PASSWORD` en `DB_PROVISIONER_HOST` weg. Zolang dat
wachtwoord er staat kan alles wat de `.env` leest — de webserver dus ook —
elke klantdatabase weggooien, en klaagt de doctor daarover.

Het brede recht is nodig: MySQL laat een account met alleen een
wildcard-recht (`lavoro\_tenant\_%`) géén rechten uitdelen op een nieuwe
database, en dat is precies wat aanmaken vereist.

## Klanten

```bash
php artisan tenants:list                                  # naam, database, gebruikers
php artisan tenant:overview                               # plaatsen, opslag, maandprijs

php artisan tenant:create "Bedrijf BV" beheer@bedrijf.nl  # klant + eerste beheerder
php artisan tenant:delete <id>                            # alles weg, vraagt bevestiging
```

Kan ook in het beheerpaneel onder `/beheer`. Dat zet een aanvraag klaar die de
provisioning-worker uitvoert; blijft hij op "in de wacht" staan, dan draait die
worker niet.

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
