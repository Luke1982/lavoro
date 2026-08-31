# De provisioning-worker

Tenants aanmaken en verwijderen kan alleen het MySQL-account
`lavoro_provisioner`, en dat hangt aan de Linux-gebruiker met dezelfde naam.
De webserver draait als `lavoro_app` en mag dat met opzet niet: kon het paneel
zelf databases maken, dan kon een fout in het paneel er ook een weggooien.

Het beheerpaneel zet daarom een regel in `tenant_provisioning_requests` en deze
worker voert hem uit:

```bash
sudo -u lavoro_provisioner php /pad/naar/lavoro/artisan queue:work \
    --queue=provisioning --tries=1 --sleep=5
```

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
