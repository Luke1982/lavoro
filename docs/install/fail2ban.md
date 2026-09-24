# Locking out password guessing

Somebody who wants into an account will try a lot of passwords. Two things stop
that.

**1. Lavoro itself.** Both login screens — the one customers use and the admin
panel at `/beheer` — allow five attempts per minute for one email address, and
twenty per minute from one IP address. After that the login screen refuses to
try at all for a while. This needs no setup; it is always on.

**2. fail2ban.** This is a standard Linux tool that watches a log file and
blocks IP addresses in the firewall when they show up too often. Lavoro writes
every refused login to a log file for it to read. Setting that up is what this
page is about.

## The log file

Every refused login is written to `storage/logs/auth.log`. Nothing else is
written to that file, so every line in it is a failed login attempt:

```
[2026-09-24 07:12:44] WARNING: Failed login guard=web email="x@y.nl" ip=203.0.113.9
[2026-09-24 07:13:02] WARNING: Login blocked after too many attempts guard=landlord email="a@b.nl" ip=203.0.113.9
```

`guard=web` means somebody tried to log in as a customer's user.
`guard=landlord` means the admin panel.

The email address is typed by whoever is trying to log in, so before it is
written to the file, everything that cannot appear in an email address is
removed. Without that, somebody could type an address containing a line break
and write their own fake lines into the log — a fake failed login naming any IP
address they choose, which fail2ban would then block.

## Setting it up

Run these from the folder Lavoro is installed in:

```bash
sudo apt install fail2ban                              # if it is not installed yet
sudo scripts/tenancy/setup-fail2ban.sh --dry-run       # shows both files, changes nothing
sudo scripts/tenancy/setup-fail2ban.sh
```

Until you run this, `storage/logs/auth.log` fills up and nobody is ever blocked.

The script writes two files: a filter
(`/etc/fail2ban/filter.d/lavoro-auth.conf`) that describes what a failed login
looks like, and a jail (`/etc/fail2ban/jail.d/lavoro.conf`) that says what to do
about it. It then tests the filter against the real log file and reloads
fail2ban.

By default, ten refused logins within ten minutes get that IP address blocked
for an hour. Change those numbers with `--maxretry=`, `--findtime=` and
`--bantime=`.

Checking and undoing a block:

```bash
sudo fail2ban-client status lavoro-auth                    # who is blocked
sudo fail2ban-client set lavoro-auth unbanip 203.0.113.9   # let somebody back in
```

## If the server is behind a proxy

The IP address in the log is the address Lavoro sees. If you put Cloudflare, a
load balancer or any other reverse proxy in front of the server without
configuring Laravel for it, every request appears to come from the proxy. Then
fail2ban blocks the proxy, and nobody can reach the site at all.

If you add a proxy, first set the trusted proxies in `bootstrap/app.php`, then
check that a line in `auth.log` shows a real visitor's IP address before
relying on the blocking.

## Keeping the log file from growing forever

The file grows slowly, but nothing empties it. Let logrotate handle it by
creating `/etc/logrotate.d/lavoro-auth`:

```
/var/www/lavoro/storage/logs/auth.log {
    weekly
    rotate 12
    compress
    missingok
    notifempty
    copytruncate
}
```

Use the real path of your installation on the first line. `copytruncate` is
needed so that fail2ban keeps reading the same file after a rotation.

Twelve weeks is a suggestion. The file contains email addresses and visitors' IP
addresses, so do not keep it longer than you have a reason to.

## Next

- [The runbook](../operations/runbook.md) — running the server day to day
- [Troubleshooting](../operations/troubleshooting.md) — if somebody is locked
  out and you are not sure why
