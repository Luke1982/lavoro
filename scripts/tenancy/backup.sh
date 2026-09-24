#!/usr/bin/env bash
#
# Dumps every database: the central one and every customer's.
#
#   scripts/tenancy/backup.sh
#   scripts/tenancy/backup.sh --to=/backups/lavoro --keep-days=14
#
# Each customer has a login of its own, so there is no single account that can
# read them all. This asks the application which databases there are and what to
# reach each one with (tenancy:backup-targets), and dumps them one by one.
#
# It stops at nothing halfway: a customer whose database will not open is named
# and the rest is still dumped, but the exit code is not zero -- an incomplete
# backup that reports success is worse than none.
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

TO="${PROJECT_ROOT}/storage/backups"
KEEP_DAYS=""

while [ $# -gt 0 ]; do
    case "$1" in
        --to=*)         TO="${1#*=}" ;;
        --to)           TO="$2"; shift ;;
        --keep-days=*)  KEEP_DAYS="${1#*=}" ;;
        --keep-days)    KEEP_DAYS="$2"; shift ;;
        -h|--help)
            sed -n '2,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
    shift
done

preflight_common
require_commands mysqldump gzip du

if [ -n "$KEEP_DAYS" ] && ! [[ "$KEEP_DAYS" =~ ^[0-9]+$ ]]; then
    die "--keep-days takes a number of days, not '${KEEP_DAYS}'."
fi

# Artisan runs as the account the application belongs to: as root it leaves
# root-owned caches and logs behind, and the next web request cannot write them.
APP_ACCOUNT="$(stat -c %U "$PROJECT_ROOT/artisan")"
artisan() {
    if [ "$(id -un)" = "$APP_ACCOUNT" ]; then
        "$(command -v php)" "$PROJECT_ROOT/artisan" "$@"
    else
        sudo -u "$APP_ACCOUNT" "$(command -v php)" "$PROJECT_ROOT/artisan" "$@"
    fi
}

mkdir -p "$TO" || die "Cannot write in ${TO}."
[ -w "$TO" ] || die "${TO} is not writable for $(id -un)."

STAMP="$(date +%Y-%m-%d_%H-%M-%S)"

info "==> Backup"
info "  to:   ${TO}"
info ""

# Never printed, never written to a file: every line carries a password.
if ! LINES="$(artisan tenancy:backup-targets)"; then
    die "Could not ask the application which databases there are.
Does 'php artisan tenancy:backup-targets' work as ${APP_ACCOUNT}?"
fi

FAILED=0
DUMPED=0

# A here-string and not a pipe: in 'echo | while' the loop runs in a subshell,
# so everything it counts is forgotten again at the end of it.
while IFS=$'\t' read -r MARK REST; do
    [ "$MARK" = "SKIP" ] || continue

    warn "  skipped, database will not open: ${REST}"
    FAILED=$((FAILED + 1))
done <<< "$LINES"

while IFS=$'\t' read -r MARK DB USER PASS HOST PORT; do
    [ "$MARK" = "DUMP" ] || continue

    # Through a file and not on the command line, where anyone running ps reads
    # the password along.
    CONFIG="$(mktemp)"
    chmod 600 "$CONFIG"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$USER" "$PASS" "$HOST" "$PORT" > "$CONFIG"

    TARGET="${TO}/${DB}-${STAMP}.sql.gz"

    # Written to .part first and moved when it is whole: a backup that broke
    # off halfway looks exactly like a good one until the day you need it.
    #
    # --no-tablespaces: reading tablespaces asks for the PROCESS right, and
    # these accounts deliberately do not have it.
    if mysqldump --defaults-extra-file="$CONFIG" --single-transaction --routines \
        --no-tablespaces "$DB" 2>/dev/null | gzip > "${TARGET}.part" \
        && mv "${TARGET}.part" "$TARGET"; then
        chmod 600 "$TARGET"
        green "  ${DB} ($(du -h "$TARGET" | cut -f1))"
        DUMPED=$((DUMPED + 1))
    else
        rm -f "${TARGET}.part"
        red "  ${DB} FAILED" >&2
        FAILED=$((FAILED + 1))
    fi

    rm -f "$CONFIG"
done <<< "$LINES"

if [ -n "$KEEP_DAYS" ]; then
    REMOVED="$(find "$TO" -maxdepth 1 -name '*.sql.gz' -mtime "+${KEEP_DAYS}" -print -delete | wc -l)"
    info ""
    info "  ${REMOVED} dump(s) older than ${KEEP_DAYS} days removed from ${TO}"
fi

info ""

if [ "$FAILED" -gt 0 ]; then
    red "${DUMPED} database(s) dumped, ${FAILED} did not. This backup is not complete."
    exit 1
fi

green "${DUMPED} database(s) dumped into ${TO}"
info ""
info "Not in here: the uploaded files and APP_KEY. Without that key the passwords"
info "and connections in these dumps cannot be read back."
info "  rsync -a ${PROJECT_ROOT}/storage/tenant-* <elsewhere>/files/"
info "  ${PROJECT_ROOT}/.env  (APP_KEY)"
