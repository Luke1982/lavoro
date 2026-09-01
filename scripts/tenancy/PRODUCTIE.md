# Van nul naar draaiend op productie

Eén doorlopende lijst. Van een lege server tot de bestaande installatie erin,
in de volgorde waarin je het doet. Elke stap heeft een controle; komt die er
niet uit zoals hier staat, dan stop je daar.

Ga uit van **MariaDB 10.11** — dat draait op productie, en het verschilt op een
paar punten van MySQL. Waar dat uitmaakt staat het erbij.

Reken op een uur, plus de tijd van de dump. De bestaande installatie gaat pas
in stap 9 op slot; alles daarvoor kun je doen terwijl hij gewoon doordraait.

---

## Voordat je begint

Verzamel dit; halverwege ernaar zoeken is hoe het misgaat.

- [ ] Root- of sudo-toegang op de nieuwe server
- [ ] Een databasegebruiker die `CREATE USER` en `GRANT` mag
- [ ] Het pad van de bestaande installatie, en of die op deze server staat
- [ ] `APP_KEY` uit de `.env` van de bestaande installatie — **zonder die
      sleutel is elke opgeslagen Google-koppeling en elk versleuteld veld
      onleesbaar.** Niet opnieuw genereren.
- [ ] De naam van het bedrijf zoals het op de factuur moet komen
- [ ] Een backup van de bestaande database die je hebt teruggezet en gezien

```bash
mysql --version          # verwacht: MariaDB 10.11 of hoger
php -v                   # verwacht: 8.3 of hoger
php -m | grep -E 'pcntl|posix'   # allebei nodig
```

Ontbreekt `pcntl` of `posix`, dan kan het provisioner-commando zichzelf niet
verheffen en moet je alles met `sudo -u` typen. Werkt, maar onhandig.

---

## 1. De code neerzetten

```bash
sudo mkdir -p /var/www/lavoro
sudo chown "$USER" /var/www/lavoro
git clone <repo> /var/www/lavoro
cd /var/www/lavoro
git checkout feature/multi-tenancy

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

**Controle:** `php artisan --version` geeft een versienummer en geen fout.

## 2. De socket-plugin aanzetten

Het provisioner-account hangt aan een Linux-gebruiker in plaats van aan een
wachtwoord. Daar is een plugin voor nodig die er niet altijd al is — dit is
precies de `Plugin 'auth_socket' is not loaded` die je anders krijgt.

**MariaDB:**

```sql
INSTALL SONAME 'auth_socket';
```

**MySQL 8:**

```sql
INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';
```

Daarna, op beide:

```sql
SELECT plugin_name, plugin_status FROM information_schema.plugins
 WHERE plugin_name IN ('unix_socket','auth_socket');
```

`INSTALL` overleeft een herstart, dus dit is eenmalig. Bestaat het
`.so`-bestand niet (`SELECT @@plugin_dir` en dan kijken), dan ontbreekt het
serverpakket en heeft doorgaan geen zin.

**Controle:** de `SELECT` geeft een regel met `ACTIVE`. `php artisan
tenancy:doctor` zegt het ook, met het commando erbij, zodra hij merkt dat een
wachtwoordloos account niet kan inloggen.

> Op MariaDB heet het bij het aanmaken van een gebruiker `IDENTIFIED VIA
> unix_socket`, op MySQL `IDENTIFIED WITH auth_socket`. Het script hieronder
> kiest zelf de goede.

## 3. De databaseaccounts

```bash
sudo scripts/tenancy/setup-mysql.sh --dry-run     # eerst kijken
sudo scripts/tenancy/setup-mysql.sh --write-env
```

Dit maakt: de landlord-database, `lavoro_app` (met wachtwoord, alleen die ene
database), de Linux-gebruiker `lavoro_provisioner`, en het MySQL-account
daaraan gekoppeld zonder wachtwoord. `--write-env` zet de gegevens in `.env` en
maakt eerst een kopie.

**Controle — dit is de grens waar de hele opzet op leunt:**

```bash
# mag niet kunnen:
mysql -u lavoro_app -p -e "CREATE DATABASE lavoro_tenant_proef"   # verwacht: geweigerd

# moet kunnen:
sudo -u lavoro_provisioner mysql -e "SELECT 1"                    # verwacht: 1
```

Lukt de eerste wél, dan staat `lavoro_app` te ruim en ga je niet verder.

## 4. De `.env`

Neem de `APP_KEY` over uit de oude installatie. Verder:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domein>

DB_CONNECTION=mysql
DB_DATABASE=lavoro_landlord
DB_USERNAME=lavoro_app
DB_PASSWORD=<uit stap 3>

SESSION_DRIVER=database
SESSION_CONNECTION=central
QUEUE_CONNECTION=database
CACHE_STORE=database

DB_PROVISIONER_USERNAME=lavoro_provisioner
DB_PROVISIONER_SOCKET=/var/run/mysqld/mysqld.sock

MAIL_MAILER=tenant

LANDLORD_MAIL_HOST=
LANDLORD_MAIL_PORT=587
LANDLORD_MAIL_USERNAME=
LANDLORD_MAIL_PASSWORD=
LANDLORD_MAIL_FROM_ADDRESS=info@majorlabel.nl
LANDLORD_MAIL_FROM_NAME=MajorLabel
```

**Geen** `DB_PROVISIONER_PASSWORD` en **geen** `DB_PROVISIONER_HOST`. Staan die
er, dan kan alles wat de `.env` leest — de webserver dus ook — elke
klantdatabase weggooien, en klaagt de controle in stap 8 daarover.

`MAIL_MAILER=tenant` betekent: elke klant verstuurt met zijn eigen
mailinstellingen. `LANDLORD_MAIL_*` is jouw eigen server, alleen voor de
facturen die jij aan klanten stuurt.

```bash
php artisan migrate --force
```

**Controle:** `php artisan tenants:list` geeft een lege tabel en geen fout.

## 5. Het beheerpaneel

```bash
php artisan landlord:user jij@majorlabel.nl
```

Wachtwoord komt op het scherm. Dit account staat in de landlord-database en
heeft niets te maken met de gebruikers van een klant.

**Controle:** `https://<domein>/beheer` geeft een inlogscherm, en je komt
binnen. Nog geen klanten in de lijst; dat klopt.

Zet daarna onder **Catalogus → Facturatie** je eigen gegevens: adres, KvK, BTW,
IBAN, betaaltermijn, en het incassant-ID als je gaat incasseren.

## 6. De twee workers

Twee, en dat is met opzet: de gewone draait als het account van de applicatie
dat géén databases mag maken, de tweede is de enige die dat wel mag.

`/etc/systemd/system/lavoro-worker.service`:

```ini
[Unit]
Description=Lavoro worker
After=mariadb.service

[Service]
User=www-data
WorkingDirectory=/var/www/lavoro
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/lavoro-provisioning.service`:

```ini
[Unit]
Description=Lavoro provisioning worker
After=mariadb.service

[Service]
User=lavoro_provisioner
Group=lavoro_provisioner
WorkingDirectory=/var/www/lavoro
ExecStart=/usr/bin/php artisan queue:work --queue=provisioning --tries=1 --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now lavoro-worker lavoro-provisioning
sudo systemctl status lavoro-worker lavoro-provisioning
```

`--tries=1` op de tweede is geen slordigheid: een half aangemaakte klant nog
eens proberen loopt vast op "de database bestaat al" en verbergt de echte fout.

`lavoro_provisioner` moet de projectmap kunnen lezen en in `storage/` kunnen
schrijven — hij maakt de mappen van nieuwe klanten aan:

```bash
sudo setfacl -R -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
sudo setfacl -R -d -m u:lavoro_provisioner:rwX /var/www/lavoro/storage
```

## 7. De cron

```bash
sudo crontab -u www-data -e
```

```
* * * * * cd /var/www/lavoro && php artisan schedule:run >> /dev/null 2>&1
```

Zonder deze regel gebeurt er niets vanzelf: geen facturen, geen
Google-synchronisatie, geen werkbonnen uit onderhoudscontracten. Dat valt pas
weken later op.

**Controle:** wacht vijf minuten, dan `php artisan tenancy:doctor`. De
planner-hartslag hoort er te staan.

## 8. Alles nakijken

```bash
php artisan tenancy:doctor
```

Loopt de opstelling na en geeft exitcode 1 bij een probleem. Wat er hier moet
staan:

- `lavoro_app kan geen klantdatabases maken of weggooien`
- `MySQL-account lavoro_provisioner ... bestaat en werkt` — of, als je dit als
  jezelf draait, de mededeling dat het hiervandaan niet te zien is. Dat is
  goed: het betekent dat jouw account er niet bij kan.
- `Linux-gebruiker lavoro_provisioner bestaat`
- **Geen** klacht over een wachtwoord in de `.env`
- `planner draait`

Klaagt hij, dan los je dat nu op. Alles hierna gaat over echte klantgegevens.

## 9. De bestaande installatie erin

Vanaf hier ligt de oude installatie stil. Doe dit buiten werktijd.

```bash
# oude installatie op slot
cd /pad/naar/oude/lavoro
php artisan down

# verse backup, nu er niemand meer schrijft
mysqldump --single-transaction --routines <oude_db> > ~/lavoro-voor-overzetten.sql
```

Bewaar die dump ergens waar hij een week blijft staan. Dan:

```bash
cd /var/www/lavoro
sudo -u lavoro_provisioner php artisan tenancy:doctor    # nog één keer

scripts/tenancy/import-install.sh \
    --from /pad/naar/oude/lavoro \
    --name "Spee Totaaltechniek" \
    --slug spee \
    --package business \
    --dry-run

# als dat klopt, zonder --dry-run
```

Wat het doet: dumpt de oude database, zet hem terug als
`lavoro_tenant_<slug>`, gooit de tabellen eruit die nu centraal staan
(`sessions`, `cache`, `jobs`), registreert de klant, werkt het schema bij,
kopieert `storage/app/public` en `storage/app/private` naar de map van de
klant, en zet het pakket.

**De bestaande gebruikers gaan mee, met hun eigen wachtwoord.** Het commando
zet hun e-mailadressen in de centrale lijst waarmee het inloggen de klant
opzoekt. Je hoeft geen nieuwe beheerder te maken.

**Controle:**

```bash
php artisan tenants:list      # de klant staat er, met het aantal gebruikers
php artisan tenancy:doctor    # alles in orde, geen databases zonder klant
```

## 10. Kijken of het echt werkt

Niet alleen of het opstart. Dit zijn de dingen die stil kapot gaan:

- [ ] Inloggen met een bestaand account, met het oude wachtwoord
- [ ] Een klantenlijst: staat het juiste aantal erin
- [ ] **Een foto op een werkbon opent.** Bestanden verhuizen mee naar een
      andere map; is dat misgegaan, dan zie je geen foutmelding maar een lege
      plek.
- [ ] De planner toont afspraken (die komen via de API, een ander pad dan de
      rest)
- [ ] Een werkbon-PDF genereren
- [ ] Een testmail sturen onder **Technisch beheer**
- [ ] De AI-assistent een vraag stellen, als die is afgenomen
- [ ] In `/beheer`: de klant staat er met het juiste pakket, plaatsen en opslag

```bash
php artisan queue:work --once -v    # één job, kijk of hij goed afloopt
```

## 11. Openzetten

```bash
php artisan config:cache route:cache view:cache
sudo systemctl restart lavoro-worker lavoro-provisioning php8.3-fpm
php artisan up
```

Laat de oude installatie een week staan met de webserver eruit. Niet weggooien.

## 12. Een tweede klant

Nu pas, en op een rustige dag: dit is de eerste keer dat er echt een database
aangemaakt wordt.

Via `/beheer` → **Nieuwe tenant**, of:

```bash
php artisan tenant:create "Tweede Klant BV" beheer@tweede.nl --package=starter
```

Maak je hem in het paneel, dan komt er een aanvraag in de lijst die de
provisioning-worker oppakt. Blijft die op "in de wacht" staan, dan draait die
worker niet.

**Controle:** log in als de nieuwe klant en kijk of je een lege installatie
ziet — en vooral: **niet de gegevens van de eerste klant.** Vraag daarna een
bestand op van de eerste klant terwijl je als de tweede bent ingelogd; dat
hoort 404 te geven.

---

## Als het misgaat

| Wanneer | Wat |
| --- | --- |
| Vóór stap 9 | Niets aan de hand, de oude installatie draait nog. Opnieuw beginnen kan. |
| Import mislukt halverwege | `php artisan tenant:delete <id>`, of `DROP DATABASE lavoro_tenant_<slug>` plus de rij uit `tenants` en `user_tenant_lookups`. Daarna opnieuw. |
| Na stap 11, binnen een week | Oude installatie weer aanzetten (`php artisan up` daar), nieuwe eruit. Alles wat sindsdien is ingevoerd is dan wel weg. |

`php artisan tenancy:doctor` is na elke stap de goedkoopste controle. Draai hem
liever te vaak.

## Wat je hierna nog moet doen

- Een backup van de landlord-database **én** van elke klantdatabase. Een backup
  van alleen de landlord is een lijst met namen en verder niets.
- `APP_KEY` in de kluis. Die sleutel opent elk wachtwoord van elke
  klantdatabase, en zonder hem is niets meer te lezen.
- Per klant onder **Technisch beheer** de mailkoppeling invullen. Tot dat
  gebeurt verstuurt die klant geen mail — met opzet: uit de mailbox van een
  ander bedrijf versturen is erger dan niet versturen.

## Verder

| | |
| --- | --- |
| `README.md` | dagelijkse bediening |
| `README-provisioning.md` | de provisioning-worker |
| `../../docs/tenancy-testrisicos.md` | waar dit stuk breekt |
| `../../docs/superpowers/plans/2026-06-09-multi-database-tenancy.md` | het waarom |
