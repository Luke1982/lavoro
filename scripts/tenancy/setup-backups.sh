#!/usr/bin/env bash
#
# Puts the nightly database backup in place: a systemd timer that runs
# scripts/tenancy/backup.sh every night and keeps the newest seven runs.
#
#   sudo scripts/tenancy/setup-backups.sh --dry-run
#   sudo scripts/tenancy/setup-backups.sh
#   sudo scripts/tenancy/setup-backups.sh --at=02:15 --keep=14 --to=/backups/lavoro
#
# It runs as the account the site runs as, not as the provisioner. A backup only
# reads, and it reads each database with that database's own login, which the
# application hands it. The provisioner may create and drop databases and
# users; a process that runs every night with nobody watching should not hold
# that.
#
# It ends with one run straight away, so you know now and not next week whether
# it works. Run it again to change the time, the folder or how many are kept.

set -euo pipefail

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
TO="storage/backups/scheduled"
KEEP=7
AT="03:30"
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage: sudo scripts/tenancy/setup-backups.sh [options]

  --to=DIR      Where the runs go; relative to the installation unless it
                starts with /. Defaults to storage/backups/scheduled, which is
                the same disk as the databases: copy it off the machine too.
  --keep=N      How many complete runs to keep. Defaults to 7.
  --at=HH:MM    When it runs, every night. Defaults to 03:30.
  --user=NAME   The account the site runs as. Defaults to the owner of the
                files.
  --dry-run     Show what would be put in place.
  --help
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --to=*)    TO="${1#*=}" ;;
        --keep=*)  KEEP="${1#*=}" ;;
        --at=*)    AT="${1#*=}" ;;
        --user=*)  APP_ACCOUNT="${1#*=}" ;;
        --dry-run) DRY_RUN=1 ;;
        --help|-h) usage; exit 0 ;;
        *)         usage; die "Unknown option: $1" ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 0 ]; then
    require_root
fi

[[ "$KEEP" =~ ^[1-9][0-9]*$ ]] || die "--keep takes a number of runs, at least 1, not '${KEEP}'."
[[ "$AT" =~ ^([01][0-9]|2[0-3]):[0-5][0-9]$ ]] || die "--at takes a time like 03:30, not '${AT}'."

case "$TO" in
    /*) ;;
    *)  TO="${PROJECT_ROOT}/${TO}" ;;
esac

[[ "$TO" != *[[:space:]]* ]] || die "--to cannot contain a space: systemd splits ExecStart on it."

command -v systemctl >/dev/null 2>&1 || die "This server does not run systemd, so a timer does nothing.
Run this every night some other way, as the account the site runs as:
  ${PROJECT_ROOT}/scripts/tenancy/backup.sh --to=${TO} --keep=${KEEP}"

APP_ACCOUNT="${APP_ACCOUNT:-$(stat -c %U "$PROJECT_ROOT/artisan")}"
PROV_ACCOUNT="$(env_value DB_PROVISIONER_USERNAME)"
PROV_ACCOUNT="${PROV_ACCOUNT:-$PROV_USER}"

id "$APP_ACCOUNT" >/dev/null 2>&1 || die "The user '${APP_ACCOUNT}' does not exist. Give --user=NAME."
[ "$APP_ACCOUNT" != "root" ] || die "Not as root: artisan as root leaves files behind the site cannot write."
[ "$APP_ACCOUNT" != "$PROV_ACCOUNT" ] || die "Not as ${PROV_ACCOUNT}: a backup only reads, and the provisioner may drop databases."

# The php the workers run on, by its full path: a unit gets a bare PATH, and
# a 'php' found there can be another version, or none at all.
PHP_PATH="$(php -r 'echo PHP_BINARY;' 2>/dev/null || command -v php)"

SERVICE_UNIT="[Unit]
Description=Lavoro database backup
After=$(database_unit)

[Service]
Type=oneshot
User=${APP_ACCOUNT}
WorkingDirectory=${PROJECT_ROOT}
Environment=LAVORO_PHP=${PHP_PATH}
ExecStart=${BASH} ${PROJECT_ROOT}/scripts/tenancy/backup.sh --to=${TO} --keep=${KEEP}
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=7"

# Persistent: a server that was off at that moment runs the backup as soon as it
# is back, instead of skipping a night.
TIMER_UNIT="[Unit]
Description=Lavoro database backup, every night at ${AT}

[Timer]
OnCalendar=*-*-* ${AT}:00
Persistent=true

[Install]
WantedBy=timers.target"

info "==> This is how it will stand"
info "  runs as:   ${APP_ACCOUNT}"
info "  at:        ${AT}, every night"
info "  into:      ${TO}"
info "  keeps:     the newest ${KEEP} complete runs"
info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "--- /etc/systemd/system/lavoro-backup.service"
    printf '%s\n\n' "$SERVICE_UNIT"
    info "--- /etc/systemd/system/lavoro-backup.timer"
    printf '%s\n\n' "$TIMER_UNIT"
    info "Nothing changed (--dry-run)."
    exit 0
fi

# Found out here rather than at half past three: a folder the account cannot
# create is a backup that never runs.
sudo -u "$APP_ACCOUNT" mkdir -p "$TO" 2>/dev/null || die "${APP_ACCOUNT} cannot create ${TO}. Make it and hand it over:
  mkdir -p ${TO} && chown ${APP_ACCOUNT}: ${TO}"

printf '%s\n' "$SERVICE_UNIT" > /etc/systemd/system/lavoro-backup.service
printf '%s\n' "$TIMER_UNIT" > /etc/systemd/system/lavoro-backup.timer
chmod 0644 /etc/systemd/system/lavoro-backup.service /etc/systemd/system/lavoro-backup.timer

# Restarted and not only enabled: a timer that already runs keeps the time it
# was started with, so running this again to change --at would change nothing.
systemctl daemon-reload
systemctl enable --quiet lavoro-backup.timer
systemctl restart lavoro-backup.timer

info "==> A first run now"
RESULT=0
systemctl start lavoro-backup.service || RESULT=$?

journalctl --no-pager -o cat \
    _SYSTEMD_INVOCATION_ID="$(systemctl show -p InvocationID --value lavoro-backup.service)" \
    | sed 's/^/  /' || true
info ""

[ "$RESULT" -eq 0 ] || die "The first run failed, so every night will fail the same way. The log:
  journalctl -u lavoro-backup -n 50"

green "The backup works, and runs every night at ${AT}."
systemctl list-timers lavoro-backup.timer --no-pager | sed 's/^/  /' || true
info ""
info "Not covered by this: the uploaded files, APP_KEY, and a copy on another"
info "machine. See docs/operations/backup-restore.md."
