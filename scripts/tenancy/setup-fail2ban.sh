#!/usr/bin/env bash
#
# Teaches fail2ban to read Lavoro's refused logins.
#
#   sudo scripts/tenancy/setup-fail2ban.sh --dry-run   # shows the files, writes nothing
#   sudo scripts/tenancy/setup-fail2ban.sh
#
# Writes one filter and one jail. The filter matches the lines the application
# writes to storage/logs/auth.log -- a refused login and a refused attempt after
# too many tries -- and nothing else lives in that file, so a match means one
# thing.
#
# The application throttles those logins itself, five a minute per address. This
# is the second line: somebody who keeps going loses the route to the server for
# a while, instead of holding a connection open all night.
set -euo pipefail
case "${BASH_SOURCE[0]}" in
    */*) SCRIPT_DIR="$(cd "${BASH_SOURCE[0]%/*}" && pwd)" ;;
    *)   SCRIPT_DIR="$PWD" ;;
esac
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

DRY_RUN=0
BAN_TIME="1h"
FIND_TIME="10m"
MAX_RETRY=10

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)     DRY_RUN=1 ;;
        --bantime=*)   BAN_TIME="${1#*=}" ;;
        --findtime=*)  FIND_TIME="${1#*=}" ;;
        --maxretry=*)  MAX_RETRY="${1#*=}" ;;
        -h|--help)
            sed -n '2,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
    shift
done

preflight_common

LOG="${PROJECT_ROOT}/storage/logs/auth.log"

FILTER='# /etc/fail2ban/filter.d/lavoro-auth.conf
# Created by scripts/tenancy/setup-fail2ban.sh
#
# The lines this matches are written by App\Listeners\Auth\RecordAuthFailure,
# and tests/Feature/Security/RefusedLoginsAreLoggedTest holds this expression
# against a real one. Change either and that test says so.
#
#   [2026-09-24 07:12:44] WARNING: Failed login guard=web email="x@y.nl" ip=203.0.113.9
#   [2026-09-24 07:13:02] WARNING: Login blocked after too many attempts guard=landlord email="a@b.nl" ip=203.0.113.9

[Definition]
failregex = ^\[[^\]]+\] WARNING: (Failed login|Login blocked[^=]*) guard=\S+ email="[^"]*" ip=<HOST>$
ignoreregex =
datepattern = ^\[%%Y-%%m-%%d %%H:%%M:%%S\]
'

JAIL="# /etc/fail2ban/jail.d/lavoro.conf
# Created by scripts/tenancy/setup-fail2ban.sh

[lavoro-auth]
enabled  = true
filter   = lavoro-auth
logpath  = ${LOG}
maxretry = ${MAX_RETRY}
findtime = ${FIND_TIME}
bantime  = ${BAN_TIME}
port     = http,https
"

info "==> fail2ban for Lavoro"
info "  log:      ${LOG}"
info "  ban:      ${MAX_RETRY} refusals within ${FIND_TIME} costs ${BAN_TIME}"
info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "--- /etc/fail2ban/filter.d/lavoro-auth.conf"
    printf '%s\n' "$FILTER"
    info "--- /etc/fail2ban/jail.d/lavoro.conf"
    printf '%s\n' "$JAIL"
    info "Nothing changed (--dry-run)."
    exit 0
fi

require_root
require_commands fail2ban-client

[ -f "$LOG" ] || warn "  ${LOG} does not exist yet. It appears on the first refused login;
  fail2ban watches the path and picks it up by itself."

printf '%s\n' "$FILTER" > /etc/fail2ban/filter.d/lavoro-auth.conf
printf '%s\n' "$JAIL" > /etc/fail2ban/jail.d/lavoro.conf
chmod 0644 /etc/fail2ban/filter.d/lavoro-auth.conf /etc/fail2ban/jail.d/lavoro.conf

# Against the real file, so a filter that matches nothing is caught here and not
# in three weeks when nobody was banned.
if [ -f "$LOG" ]; then
    info "  Testing the filter against ${LOG}:"
    fail2ban-regex "$LOG" /etc/fail2ban/filter.d/lavoro-auth.conf | grep -E "Failregex:|Lines:" | sed 's/^/    /'
fi

systemctl reload fail2ban 2>/dev/null || systemctl restart fail2ban

green "Done."
info ""
info "Check:"
info "  sudo fail2ban-client status lavoro-auth"
info "  sudo fail2ban-client set lavoro-auth unbanip <address>   # let someone back in"
