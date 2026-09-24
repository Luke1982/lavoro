# Locking out password guessing

Two things stand between somebody and a list of passwords:

1. **The application itself.** Both logins — the customers' and the panel at
   `/beheer` — allow five attempts a minute per address, and twenty a minute
   from one place. That needs no setup; it is on.
2. **fail2ban**, which takes the route to the server away from whoever keeps
   going. That is what this page sets up.

Every refused login is written to `storage/logs/auth.log`, and nothing else is:

```
[2026-09-24 07:12:44] WARNING: Failed login guard=web email="x@y.nl" ip=203.0.113.9
[2026-09-24 07:13:02] WARNING: Login blocked after too many attempts guard=landlord email="a@b.nl" ip=203.0.113.9
```

`guard=web` is a customer's login, `guard=landlord` the panel. The address is
stripped of everything an address cannot contain before it is written: it comes
from whoever is typing, and a newline in it would let them write their own lines
— a refusal naming any address they like, and fail2ban banning whoever they
point at.

## Setting it up

```bash
sudo apt install fail2ban                              # if it is not there yet
sudo scripts/tenancy/setup-fail2ban.sh --dry-run       # shows both files, writes nothing
sudo scripts/tenancy/setup-fail2ban.sh
```

It writes a filter (`/etc/fail2ban/filter.d/lavoro-auth.conf`) and a jail
(`/etc/fail2ban/jail.d/lavoro.conf`), tests the filter against the real log, and
reloads fail2ban. Ten refusals within ten minutes costs an hour; change it with
`--maxretry=`, `--findtime=` and `--bantime=`.

```bash
sudo fail2ban-client status lavoro-auth
sudo fail2ban-client set lavoro-auth unbanip 203.0.113.9   # let someone back in
```

## Behind a proxy

The address in the log is the one the application sees. Put Cloudflare, a load
balancer or another reverse proxy in front without telling Laravel about it, and
every line carries the proxy's address — fail2ban then bans the proxy, which
takes everyone out at once. If you add one, set trusted proxies in
`bootstrap/app.php` first, and check a line in the log shows a real visitor's
address before you trust the jail.

## Keeping the file in hand

The file grows slowly, but it is never emptied. Hand it to logrotate:

```
/home/lavoro/lavorofsm/storage/logs/auth.log {
    weekly
    rotate 12
    compress
    missingok
    notifempty
    copytruncate
}
```

`copytruncate` keeps fail2ban reading the same file. Twelve weeks is a choice,
not a rule — the file holds email addresses and visitors' IP addresses, so keep
it no longer than it is useful to you.
