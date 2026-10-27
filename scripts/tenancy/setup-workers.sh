#!/usr/bin/env bash
#
# Puts the two queue workers and the scheduler in place.
#
# Without these three nothing happens by itself: no invoices, no calendar
# synchronisation, no service orders from maintenance contracts, and a new
# customer stays waiting.
#
#   sudo scripts/tenancy/setup-workers.sh --dry-run
#   sudo scripts/tenancy/setup-workers.sh
#
# The account and the path are read here and not assumed: an installation in a
# home directory runs under a different account than one in /var/www, and a unit
# with the wrong account in it does start and does nothing.

set -euo pipefail

# Without dirname: this sits above the sourcing of lib.sh, so an error here
# comes out as a complaint about a file that cannot be found. The shell can do
# this itself, and then nothing has to exist to get here.
case "${BASH_SOURCE[0]}" in
    */*) SCRIPT_DIR="$(cd "${BASH_SOURCE[0]%/*}" && pwd)" ;;
    *)   SCRIPT_DIR="$PWD" ;;
esac
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

preflight_common
require_commands stat php

APP_ACCOUNT=""
DRY_RUN=0
WITH_CRON=1

usage() {
    cat <<'USAGE'
Usage: sudo scripts/tenancy/setup-workers.sh [options]

  --user=NAME   The account the site runs as. Defaults to the owner of the
                files.
  --no-cron     Only the workers, no line for the scheduler.
  --dry-run     Show what would be put in place.
  --help
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --user=*)  APP_ACCOUNT="${1#*=}" ;;
        --no-cron) WITH_CRON=0 ;;
        --dry-run) DRY_RUN=1 ;;
        --help|-h) usage; exit 0 ;;
        *)         usage; die "Unknown option: $1" ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 0 ]; then
    require_root
fi

command -v systemctl >/dev/null 2>&1 || die "This server does not run systemd, so these units do nothing.
Make sure these two keep running yourself, one way or another:
  php artisan queue:work --sleep=3 --tries=3
  php artisan queue:work --queue=provisioning --tries=1 --sleep=5
and that 'php artisan schedule:run' runs every minute."

# The account the files belong to is the account the site runs as. Assuming
# that is www-data is right exactly as often as it is wrong.
if [ -z "$APP_ACCOUNT" ]; then
    APP_ACCOUNT="$(stat -c %U "$PROJECT_ROOT/artisan")"
fi

# With --dry-run only say what is wrong: asking for a preview on a machine
# where nothing is in place yet should work.
account_missing() {
    local account="$1" advice="$2"

    id "$account" >/dev/null 2>&1 && return 0

    if [ "$DRY_RUN" -eq 1 ]; then
        warn "  The user '${account}' does not exist here. ${advice}"
        return 0
    fi

    die "The user '${account}' does not exist. ${advice}"
}

account_missing "$APP_ACCOUNT" "Geef --user=NAAM mee."

PROV_ACCOUNT="$(env_value DB_PROVISIONER_USERNAME)"
PROV_ACCOUNT="${PROV_ACCOUNT:-$PROV_USER}"

account_missing "$PROV_ACCOUNT" "Draai eerst: sudo scripts/tenancy/setup-mysql.sh"

PHP_PATH="$(php -r 'echo PHP_BINARY;' 2>/dev/null || command -v php)"

# What the unit has to wait for the database server on. Start the worker
# earlier and it falls over on a connection that is not there yet and systemd
# tries again -- works eventually, but fills the log with noise.
DB_UNIT="mysql.service"
for candidate in mariadb.service mysql.service mysqld.service; do
    if systemctl list-unit-files "$candidate" >/dev/null 2>&1 \
        && systemctl list-unit-files "$candidate" | grep -q "$candidate"; then
        DB_UNIT="$candidate"
        break
    fi
done

WORKER_UNIT="[Unit]
Description=Lavoro worker
After=${DB_UNIT}

[Service]
User=${APP_ACCOUNT}
WorkingDirectory=${PROJECT_ROOT}
ExecStart=${PHP_PATH} artisan queue:work --sleep=3 --tries=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"

# --tries=1 deliberately: retrying a half created customer gets stuck on
# 'database already exists' and hides the real error with it.
PROVISIONING_UNIT="[Unit]
Description=Lavoro provisioning worker
After=${DB_UNIT}

[Service]
User=${PROV_ACCOUNT}
Group=${PROV_ACCOUNT}
WorkingDirectory=${PROJECT_ROOT}
ExecStart=${PHP_PATH} artisan queue:work --queue=provisioning --tries=1 --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"

CRON_LINE="* * * * * cd ${PROJECT_ROOT} && ${PHP_PATH} artisan schedule:run >> /dev/null 2>&1"

info "==> This is how it will stand"
info "  site runs as:        ${APP_ACCOUNT}"
info "  provisioning as:     ${PROV_ACCOUNT}"
info "  directory:           ${PROJECT_ROOT}"
info "  php:                 ${PHP_PATH}"
info "  waits for:           ${DB_UNIT}"
info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "--- /etc/systemd/system/lavoro-worker.service"
    printf '%s\n\n' "$WORKER_UNIT"
    info "--- /etc/systemd/system/lavoro-provisioning.service"
    printf '%s\n\n' "$PROVISIONING_UNIT"
    if [ "$WITH_CRON" -eq 1 ]; then
        info "--- crontab of ${APP_ACCOUNT}"
        printf '%s\n\n' "$CRON_LINE"
    fi
    info "Niets gewijzigd (--dry-run)."
    exit 0
fi

printf '%s\n' "$WORKER_UNIT" > /etc/systemd/system/lavoro-worker.service
printf '%s\n' "$PROVISIONING_UNIT" > /etc/systemd/system/lavoro-provisioning.service
chmod 0644 /etc/systemd/system/lavoro-worker.service /etc/systemd/system/lavoro-provisioning.service

systemctl daemon-reload
systemctl enable --now lavoro-worker lavoro-provisioning >/dev/null 2>&1
systemctl restart lavoro-worker lavoro-provisioning

for unit in lavoro-worker lavoro-provisioning; do
    if systemctl is-active --quiet "$unit"; then
        green "  ${unit} draait"
    else
        red "  ${unit} is not running. Look with: journalctl -u ${unit} -n 30"
    fi
done

if [ "$WITH_CRON" -eq 1 ]; then
    # Remove the line first and then add it, so running again does not add one
    # every time.
    EXISTING="$(crontab -u "$APP_ACCOUNT" -l 2>/dev/null | grep -v 'artisan schedule:run' || true)"
    printf '%s\n%s\n' "$EXISTING" "$CRON_LINE" | sed '/^$/d' | crontab -u "$APP_ACCOUNT" -
    green "  scheduler is in the crontab of ${APP_ACCOUNT}"
fi

info ""
info "The workers report in within a minute. After that:"
info "  php artisan tenancy:doctor"
