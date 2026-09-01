# De provisioning-worker

Onderdeel van [de bediening](tenancy-bediening.md).

> **Dit is een inrichtingsvoorschrift, geen beschrijving van wat er draait.**
> Op de proefopstelling is `lavoro_provisioner` een gewoon MySQL-account met
> een wachtwoord in de `.env`, en bestaat de Linux-gebruiker niet. Zolang dat
> zo is, is de grens hieronder een afspraak en geen slot:
> alles wat de `.env` kan lezen kan dan ook databases weggooien.
> `php artisan tenancy:doctor` meldt dit als fout tot het is rechtgezet.

Tenants aanmaken en verwijderen hoort alleen te kunnen met het MySQL-account
`lavoro_provisioner`, gebonden aan de Linux-gebruiker met dezelfde naam.
De webserver draait als `lavoro_app` en mag dat met opzet niet: kon het paneel
zelf databases maken, dan kon een fout in het paneel er ook een weggooien.

Het beheerpaneel zet daarom een regel in `tenant_provisioning_requests` en deze
worker voert hem uit:

Eenmalig inrichten (zie ook taak 2 van het tenancyplan):

```bash
sudo adduser --system --group --no-create-home lavoro_provisioner
```

```sql
CREATE USER IF NOT EXISTS 'lavoro_provisioner'@'localhost' IDENTIFIED WITH auth_socket;
```

Daarna draait de worker als die gebruiker, zonder wachtwoord in enig bestand:

```bash
sudo -u lavoro_provisioner php /pad/naar/lavoro/artisan queue:work \
    --queue=provisioning --tries=1 --sleep=5
```

Haal `DB_PROVISIONER_PASSWORD` daarna uit de `.env`; zolang die er staat blijft
de doctor terecht klagen.

Als systemd-unit (`/etc/systemd/system/lavoro-provisioning.service`):

```ini
[Unit]
Description=Lavoro provisioning worker
After=mysql.service

[Service]
User=lavoro_provisioner
Group=lavoro_provisioner
WorkingDirectory=/pad/naar/lavoro
ExecStart=/usr/bin/php artisan queue:work --queue=provisioning --tries=1 --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Let op: `--tries=1`. Een half aangemaakte tenant nog eens proberen loopt vast op
"de database bestaat al" en verbergt daarmee de echte fout.

Draait deze worker niet, dan blijft een aanvraag in het paneel staan als "in de
wacht" en meldt `php artisan tenancy:doctor` het na een kwartier.

De gewone worker (`queue:work` zonder `--queue`) blijft als `lavoro_app`
draaien en pakt deze wachtrij niet op — dat is de bedoeling; hij zou er toch op
stuklopen.
