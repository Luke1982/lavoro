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

printf '\n\033[1mfail2ban for Lavoro\033[0m\n\n'
printf '  %-10s %s\n' "watches" "${LOG}"
printf '  %-10s %s\n' "bans" "${MAX_RETRY} refused logins within ${FIND_TIME}  ->  locked out for ${BAN_TIME}"
printf '  %-10s %s\n' "ports" "http, https"
printf '\n'

if [ "$DRY_RUN" -eq 1 ]; then
    info "--- /etc/fail2ban/filter.d/lavoro-auth.conf"
    printf '%s\n' "$FILTER"
    info "--- /etc/fail2ban/jail.d/lavoro.conf"
    printf '%s\n' "$JAIL"
    warn "Nothing was changed (--dry-run). Run it again without that flag to install this."
    exit 0
fi

require_root
require_commands fail2ban-client

write_configuration() {
    printf '%s\n' "$FILTER" > /etc/fail2ban/filter.d/lavoro-auth.conf
    printf '%s\n' "$JAIL" > /etc/fail2ban/jail.d/lavoro.conf
    chmod 0644 /etc/fail2ban/filter.d/lavoro-auth.conf /etc/fail2ban/jail.d/lavoro.conf
}

spinner_run "configuration written" write_configuration \
    || die "Could not write in /etc/fail2ban. Is fail2ban installed?"

# Against the real file, so a filter that matches nothing is caught here rather
# than in three weeks, when nobody turns out to have been banned.
TESTED=""
if [ -f "$LOG" ] && [ -s "$LOG" ]; then
    REPORT="$(mktemp)"

    if spinner_run "filter tested against the log" \
        bash -c "fail2ban-regex '${LOG}' /etc/fail2ban/filter.d/lavoro-auth.conf > '${REPORT}'"; then
        # From the summary line fail2ban-regex ends with:
        #   Lines: 4 lines, 0 ignored, 3 matched, 1 missed
        MATCHED="$(grep -oP '\K[0-9]+(?= matched)' "$REPORT" | head -1)"
        LINES="$(grep -oP 'Lines: \K[0-9]+' "$REPORT" | head -1)"
        TESTED="${MATCHED:-0} of ${LINES:-0} line(s) in the log match"

        if [ "${MATCHED:-0}" -eq 0 ] && [ "${LINES:-0}" -gt 0 ]; then
            warn "    The filter matches none of the lines already in that file."
            warn "    Those may predate the current format; a new refusal will show whether it works."
        else
            info "    ${TESTED}"
        fi
    fi

    rm -f "$REPORT"
else
    step_skip "filter not tested: no refused login has been recorded yet"
    info "    The file appears on the first one, and fail2ban picks it up by itself."
fi

spinner_run "fail2ban reloaded" bash -c 'systemctl reload fail2ban 2>/dev/null || systemctl restart fail2ban' \
    || die "fail2ban would not reload. Look at: systemctl status fail2ban"

BANNED="$(fail2ban-client status lavoro-auth 2>/dev/null | grep -oP 'Currently banned:\s*\K[0-9]+' | head -1)"

printf '\n'
if [ -n "$BANNED" ]; then
    green "  Active. ${BANNED} address(es) banned right now."
else
    warn "  Written, but the jail does not answer yet. Check: systemctl status fail2ban"
fi

printf '\n\033[1m  What you can do now\033[0m\n'
printf '    %-54s %s\n' "lavoro logs auth -f" "watch refusals as they come in"
printf '    %-54s %s\n' "sudo fail2ban-client status lavoro-auth" "who is banned at the moment"
printf '    %-54s %s\n' "sudo fail2ban-client set lavoro-auth unbanip 1.2.3.4" "let one address back in"
printf '    %-54s %s\n' "sudo lavoro setup fail2ban --bantime=4h" "ban for longer; also --findtime= and --maxretry="
printf '\n'
