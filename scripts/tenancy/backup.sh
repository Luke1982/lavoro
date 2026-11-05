#!/usr/bin/env bash
#
# Dumps every database: the central one and every customer's.
#
#   scripts/tenancy/backup.sh                                  # into storage/backups/manual
#   scripts/tenancy/backup.sh --to=storage/backups/scheduled   # what the nightly timer runs
#   scripts/tenancy/backup.sh --to=/backups/lavoro --keep=14
#
# One run is one folder, named after the moment it started, with one dump per
# database in it:
#
#   storage/backups/scheduled/2026-11-05_03-30-00/lavoro_landlord.sql.gz
#   storage/backups/scheduled/2026-11-05_03-30-00/lavoro_tenant_acme.sql.gz
#
# The folder is written as <moment>.part and gets its real name only when every
# database is in it. A run that missed one becomes <moment>-incomplete: the
# dumps in it are whole and usable, but it does not count as a backup.
#
# --keep=N (default 7) keeps the newest N complete runs in that folder and
# removes older ones, and only after a complete run: a backup that fails night
# after night never eats the last good ones. --keep=0 removes nothing. Nothing
# is ever removed that is not named like a run.
#
# LAVORO_PHP=/path/to/php picks the php that runs artisan; otherwise it is the
# one on PATH.
#
# A customer whose database will not open is named and the rest is still dumped,
# but the exit code is not zero -- an incomplete backup that reports success is
# worse than none. --allow-unreachable makes that a warning; the deploy uses it,
# because a customer that is already gone must not hold up a deploy.
#
# What this does NOT take: the uploaded files in storage/tenant-*, and APP_KEY
# from .env. Without that key every customer database password and every stored
# Google connection in these dumps is unreadable. See
# docs/operations/backup-restore.md.
set -euo pipefail

case "${BASH_SOURCE[0]}" in
    */*) SCRIPT_DIR="$(cd "${BASH_SOURCE[0]%/*}" && pwd)" ;;
    *)   SCRIPT_DIR="$PWD" ;;
esac
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

TO="${PROJECT_ROOT}/storage/backups/manual"
KEEP=7
ALLOW_UNREACHABLE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --to=*)              TO="${1#*=}" ;;
        --keep=*)            KEEP="${1#*=}" ;;
        --allow-unreachable) ALLOW_UNREACHABLE=1 ;;
        -h|--help)
            awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
            exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
    shift
done

preflight_common
require_commands mysqldump gzip du flock find sort

[[ "$KEEP" =~ ^(0|[1-9][0-9]*)$ ]] || die "--keep takes a number of runs, not '${KEEP}'."

# The nightly timer names the exact php the workers run on; by hand it is
# whichever php is on PATH.
PHP="${LAVORO_PHP:-$(command -v php || true)}"
[ -x "$PHP" ] || die "No php found. Put it on PATH, or give LAVORO_PHP=/path/to/php."

# Artisan runs as the account the application belongs to: as root it leaves
# root-owned caches and logs behind, and the next web request cannot write them.
APP_ACCOUNT="$(stat -c %U "$PROJECT_ROOT/artisan")"
artisan() {
    if [ "$(id -un)" = "$APP_ACCOUNT" ]; then
        "$PHP" "$PROJECT_ROOT/artisan" "$@"
    else
        sudo -u "$APP_ACCOUNT" "$PHP" "$PROJECT_ROOT/artisan" "$@"
    fi
}

mkdir -p "$TO" || die "Cannot create ${TO}."
[ -w "$TO" ] || die "${TO} is not writable for $(id -un)."
TO="$(cd "$TO" && pwd)"

RUN_NAME='[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}'

# The runs in this folder whose name ends in $1, with the rest handed to find.
runs() {
    local suffix="$1"
    shift
    find "$TO" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended \
        -regex ".*/${RUN_NAME}${suffix}" "$@"
}

# Two runs in one folder would each take the other's half-written run for a
# leftover and remove it.
exec 9> "${TO}/.lock"
flock -n 9 || die "Another backup is writing into ${TO} right now."

# What a run that was killed halfway left behind: a restart in the middle of the
# night, or a deploy somebody broke off.
runs '\.part' -exec rm -rf {} +

RUN="${TO}/$(date +%Y-%m-%d_%H-%M-%S)"
WORK="${RUN}.part"

info "==> Backup"
info "  to:   ${RUN}"
info ""

# Never printed, never written to a file: every DUMP line carries a password.
# Artisan writes its own error to stdout, so the rest of what it said is shown
# when it fails -- without the DUMP lines.
if ! LINES="$(artisan tenancy:backup-targets 2>&1)"; then
    red "  Could not ask the application which databases there are:" >&2
    printf '%s\n' "$LINES" | grep -v '^DUMP' | sed 's/^/    /' >&2 || true
    die "Does 'php artisan tenancy:backup-targets' work as ${APP_ACCOUNT}?"
fi

# The password goes to mysqldump through this file and not on the command line,
# where anyone running ps reads it along. mktemp makes it readable to us alone.
CONFIG="$(mktemp)"
trap 'rm -f "$CONFIG"' EXIT

mkdir -m 700 "$WORK"

DUMPED=0
FAILED=0
UNREACHABLE=0

# A here-string and not a pipe: in 'echo | while' the loop runs in a subshell,
# so everything it counts is forgotten again at the end of it.
while IFS=$'\t' read -r MARK NAME DB_USER DB_PASS DB_HOST DB_PORT; do
    case "$MARK" in
        DUMP) ;;
        SKIP)
            warn "  ${NAME}: skipped, its database will not open"
            UNREACHABLE=$((UNREACHABLE + 1))
            continue ;;
        *) continue ;;
    esac

    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
        "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" > "$CONFIG"

    TARGET="${WORK}/${NAME}.sql.gz"

    # --no-tablespaces: reading tablespaces asks for the PROCESS right, and
    # these accounts deliberately do not have it. No --routines: the application
    # keeps none, and reading one another account defined can take a right these
    # accounts do not have either -- a failed dump that would stop every deploy.
    if mysqldump --defaults-extra-file="$CONFIG" --single-transaction \
        --no-tablespaces "$NAME" | gzip > "${TARGET}.part" \
        && mv "${TARGET}.part" "$TARGET"; then
        chmod 600 "$TARGET"
        green "  ${NAME} ($(du -h "$TARGET" | cut -f1))"
        DUMPED=$((DUMPED + 1))
    else
        rm -f "${TARGET}.part"
        red "  ${NAME} FAILED" >&2
        FAILED=$((FAILED + 1))
    fi
done <<< "$LINES"

if [ "$FAILED" -gt 0 ] || { [ "$UNREACHABLE" -gt 0 ] && [ "$ALLOW_UNREACHABLE" -eq 0 ]; }; then
    info ""
    red "${DUMPED} database(s) dumped, $((FAILED + UNREACHABLE)) not. This backup is not complete." >&2

    if [ "$DUMPED" -gt 0 ]; then
        mv -T "$WORK" "${RUN}-incomplete"
        red "What was dumped is in ${RUN}-incomplete; nothing older was removed." >&2
    else
        rmdir "$WORK"
    fi

    exit 1
fi

mv -T "$WORK" "$RUN"

# Newest first: everything older than the KEEP-th complete run goes, incomplete
# runs included. Only after a complete run, so the last good ones survive any
# number of failed nights.
if [ "$KEEP" -gt 0 ]; then
    KEPT=0
    REMOVED=0

    while read -r FOLDER; do
        if [ "$KEPT" -ge "$KEEP" ]; then
            rm -rf "${TO:?}/${FOLDER}"
            REMOVED=$((REMOVED + 1))
        elif [[ "$FOLDER" != *-incomplete ]]; then
            KEPT=$((KEPT + 1))
        fi
    done < <(runs '(-incomplete)?' -printf '%f\n' | LC_ALL=C sort -r)

    info ""
    info "  the newest ${KEPT} run(s) kept, ${REMOVED} older removed"
fi

info ""
green "${DUMPED} database(s) dumped into ${RUN}"
info "Not in here: the uploaded files (storage/tenant-*) and APP_KEY (.env)."
info "See docs/operations/backup-restore.md."
