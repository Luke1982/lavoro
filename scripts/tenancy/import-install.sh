#!/usr/bin/env bash
# Imports an existing single-customer installation as a tenant.
#
# Reads that installation's .env for its database name, copies the database into
# lavoro_tenant_<slug>, registers it as a tenant, migrates it and moves the
# uploads across.
#
#   sudo scripts/tenancy/import-install.sh --from /home/spee/lavorofsm \
#        --name "Spee Totaaltechniek" --slug spee --package business [--dry-run]
#
# Run it again with --refresh to fetch the source again into the customer that
# is already here: the database is replaced, the schema brought up to date, the
# files copied over and the logins re-registered, while the customer keeps its
# id, package, seats, billing and invoices. What was changed on this side since
# the last import is gone; what is there now is dumped first.
#
# Needs root: the other installation lives under another account (its home
# directory is usually 0750, so even reading it fails), dumping its database
# needs an account with rights on it, and creating the tenant database is not
# something the app account may do. So it elevates itself rather than failing
# halfway through with "does not exist".
set -euo pipefail

case "$0" in
    */*) SCRIPT_DIR="${0%/*}" ;;
    *)   SCRIPT_DIR="." ;;
esac
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

FROM=""; NAME=""; SLUG=""; PACKAGE=""; BILLING_FROM=""; DRY=0; REFRESH=0
while [ $# -gt 0 ]; do
    case "$1" in
        --from) FROM="$2"; shift 2 ;;
        --from=*) FROM="${1#*=}"; shift ;;
        --name) NAME="$2"; shift 2 ;;
        --name=*) NAME="${1#*=}"; shift ;;
        --slug) SLUG="$2"; shift 2 ;;
        --slug=*) SLUG="${1#*=}"; shift ;;
        --package) PACKAGE="$2"; shift 2 ;;
        --package=*) PACKAGE="${1#*=}"; shift ;;
        --billing-from) BILLING_FROM="$2"; shift 2 ;;
        --billing-from=*) BILLING_FROM="${1#*=}"; shift ;;
        --dry-run) DRY=1; shift ;;
        --refresh) REFRESH=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

[ -n "$FROM" ] && [ -n "$NAME" ] && [ -n "$SLUG" ] || {
    echo "--from, --name and --slug are required" >&2; exit 2; }

# Elevate before anything else, so every check below sees what root sees. Doing
# it the other way around is what produced "source directory does not exist" for
# a directory that is plainly there.
if [ "$(id -u)" -ne 0 ]; then
    ARGS=(--from "$FROM" --name "$NAME" --slug "$SLUG")
    [ -n "$PACKAGE" ] && ARGS+=(--package "$PACKAGE")
    [ -n "$BILLING_FROM" ] && ARGS+=(--billing-from "$BILLING_FROM")
    [ "$DRY" -eq 1 ] && ARGS+=(--dry-run)
    [ "$REFRESH" -eq 1 ] && ARGS+=(--refresh)

    # Root is needed for two things: reading an installation that belongs to
    # another account, and a MySQL login that may dump the source database and
    # create the target one. Bring a privileged login of your own (ADMIN_USER
    # with ADMIN_PASSWORD, or DEFAULTS_FILE) and have the source readable, and
    # neither applies -- then it simply runs.
    #
    # Only elevated when it can be done without a password. Never prompting: the
    # app account has no general sudo right on purpose, so a prompt here is a
    # question that cannot be answered -- and giving it a NOPASSWD rule for this
    # script would be handing it root, because this script copies paths of your
    # choosing and runs commands as root.
    if [ -r "$FROM/.env" ] && [ -n "${ADMIN_PASSWORD:-}${DEFAULTS_FILE:-}" ]; then
        NEEDS_ROOT=0
    else
        NEEDS_ROOT=1
    fi

    if [ "$NEEDS_ROOT" -eq 0 ]; then
        :
    elif sudo -n true 2>/dev/null; then
        echo "  elevating to root (reading ${FROM} and creating a database need it)"
        exec sudo -n -- "$0" "${ARGS[@]}"
    else
        SELF="$(cd "${0%/*}" 2>/dev/null && pwd)/${0##*/}"

        # Single quotes, so a customer name with a space in it survives being
        # pasted. An embedded quote becomes '\'' -- the shell's own way out.
        COMMAND="bash ${SELF}"
        for arg in "${ARGS[@]}"; do
            if [[ "$arg" =~ ^[A-Za-z0-9._/=:@+-]+$ ]]; then
                COMMAND="${COMMAND} ${arg}"
            else
                COMMAND="${COMMAND} '${arg//\'/\'\\\'\'}'"
            fi
        done

        printf 'This has to run as root: %s belongs to another account, and\n' "$FROM" >&2
        printf 'creating a database is not something %s may do.\n\n' "${USER:-this account}" >&2
        printf 'In a root shell:\n\n  %s\n\n' "$COMMAND" >&2
        printf 'Or in one line from here:\n\n  su - root -c "%s"\n' "$COMMAND" >&2
        exit 1
    fi
fi

# shellcheck source=scripts/tenancy/lib.sh
. "$PROJECT_ROOT/scripts/tenancy/lib.sh"

preflight_common
require_commands mysqldump
detect_client
ensure_admin_connection

DB="${TENANT_PREFIX}${SLUG}"
run() {
    if [ "$DRY" -eq 0 ]; then
        "$@"
        return
    fi

    # Printed the way you would type it: without this a name with a space in it
    # reads as two arguments, and the line cannot be pasted.
    local line="+" arg
    for arg in "$@"; do
        if [[ "$arg" =~ ^[A-Za-z0-9._/=:@+-]+$ ]]; then
            line="${line} ${arg}"
        else
            line="${line} '${arg//\'/\'\\\'\'}'"
        fi
    done

    info "$line"
}
step() { printf '\n== %s ==\n' "$1"; }

# The application's own account, read from the files instead of assumed. Artisan
# runs as that account: as root it would leave root-owned caches and logs behind,
# and the next web request cannot write those.
APP_ACCOUNT="$(stat -c %U "$PROJECT_ROOT/artisan")"
artisan() {
    if [ "$(id -un)" = "$APP_ACCOUNT" ]; then
        run "$(command -v php)" "$PROJECT_ROOT/artisan" "$@"
    else
        run sudo -u "$APP_ACCOUNT" "$(command -v php)" "$PROJECT_ROOT/artisan" "$@"
    fi
}

step "Preflight"

[ -d "$FROM" ] || die "Source directory does not exist: $FROM"
[ -r "$FROM/.env" ] || die "Cannot read $FROM/.env, not even as root. Is the path right?"

SRC_DB=$(grep -E '^DB_DATABASE=' "$FROM/.env" | tail -1 | cut -d= -f2- | tr -d '"'"'"' ')
[ -n "$SRC_DB" ] || die "No DB_DATABASE in $FROM/.env"

sql_root "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='${SRC_DB}'" \
    | grep -q . || die "The source database ${SRC_DB} does not exist on this server."

TENANT_ROW=$(sql_root "SELECT id FROM ${LANDLORD_DB}.tenants
    WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'\$.tenancy_db_name'))='${DB}'" || true)

if sql_root "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='${DB}'" | grep -q .; then
    [ "$REFRESH" -eq 1 ] || die "${DB} already exists.
Run this again with --refresh to fetch the source again into the customer that is
already there -- it keeps its id, package, seats and billing, and everything that
was added on this side since the last import is replaced. Or choose another slug."

    [ -n "$TENANT_ROW" ] || die "${DB} exists but belongs to no customer here, so there is nothing to
refresh. Drop that database by hand, or choose another slug."
elif [ "$REFRESH" -eq 1 ]; then
    die "--refresh, but ${DB} does not exist yet. Run it without --refresh for the first import."
fi

SRC_MB=$(sql_root "SELECT COALESCE(ROUND(SUM(data_length + index_length) / 1024 / 1024), 0)
    FROM information_schema.tables WHERE table_schema='${SRC_DB}'")
FREE_MB=$(df -Pm "$PROJECT_ROOT" | awk 'NR == 2 { print $4 }')

# The dump is text and the copy lands next to it, so twice the size is the floor
# rather than the ceiling.
NEEDED_MB=$(( SRC_MB * 3 ))

[ "${FREE_MB:-0}" -gt "$NEEDED_MB" ] || die "Not enough room: ${SRC_DB} is ${SRC_MB} MB, so this needs
roughly ${NEEDED_MB} MB for the dump and the copy, and ${FREE_MB} MB is free on $(df -P "$PROJECT_ROOT" | awk 'NR == 2 { print $6 }')."

info "  source:   ${SRC_DB} (${SRC_MB} MB)"
info "  target:   ${DB}"
info "  artisan:  as ${APP_ACCOUNT}"
[ "$DRY" -eq 1 ] && info "  (dry run: nothing is written)"

step "Dump and restore"

mkdir -p "$PROJECT_ROOT/storage/backups"
DUMP=$(mktemp "$PROJECT_ROOT/storage/backups/import-${SLUG}-XXXXXX.sql")
chmod 600 "$DUMP"
trap 'rm -f "$DUMP"' EXIT

if [ "$DRY" -eq 1 ]; then
    [ "$REFRESH" -eq 1 ] && info "+ mysqldump ${DB} > storage/backups/before-refresh-${SLUG}-<stamp>.sql.gz"
    [ "$REFRESH" -eq 1 ] && info "+ DROP DATABASE ${DB}"
    info "+ mysqldump ${SRC_DB} > ${DUMP}"
    info "+ CREATE DATABASE ${DB}"
    info "+ restore ${DUMP} into ${DB}"
else
    # The same privileged connection lib.sh builds for the client works for
    # mysqldump: --defaults-file first, then the socket and the account.
    mapfile -t ARGS < <(admin_args)
    MYSQL_PWD="$ADMIN_PASSWORD" mysqldump "${ARGS[@]}" --single-transaction --routines \
        --no-tablespaces "$SRC_DB" > "$DUMP"

    # What is there now, before it is replaced. A refresh throws away whatever
    # was done on this side since the last import, and "I did not mean that"
    # arrives after the fact, not before it.
    if [ "$REFRESH" -eq 1 ]; then
        ROLLBACK="$PROJECT_ROOT/storage/backups/before-refresh-${SLUG}-$(date +%Y-%m-%d_%H-%M-%S).sql.gz"
        MYSQL_PWD="$ADMIN_PASSWORD" mysqldump "${ARGS[@]}" --single-transaction --routines \
            --no-tablespaces "$DB" | gzip > "$ROLLBACK"
        chmod 600 "$ROLLBACK"
        green "  kept what was there: ${ROLLBACK#"$PROJECT_ROOT/"}"

        sql_root "DROP DATABASE \`${DB}\`"
    fi

    sql_root "CREATE DATABASE \`${DB}\` CHARACTER SET ${CHARSET} COLLATE ${COLLATION}"

    # The dump can bring its own CREATE DATABASE/USE along, which would silently
    # send everything back into the source database.
    sed -e "/^CREATE DATABASE .*\`${SRC_DB}\`/d" -e "/^USE \`${SRC_DB}\`/d" "$DUMP" \
        | MYSQL_PWD="$ADMIN_PASSWORD" "$MYSQL_CLIENT" "${ARGS[@]}" "$DB"

    TABLES=$(sql_root "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB}'")
    [ "${TABLES:-0}" -gt 0 ] || die "The restore produced 0 tables."
    green "  ${TABLES} tables"
fi

step "Removing central tables from the copy"
# These live in the landlord database from now on. Left behind they are a second
# copy that nothing writes to and everything ignores.
if [ "$DRY" -eq 1 ]; then
    info "+ DROP TABLE sessions, cache, cache_locks, jobs, job_batches, failed_jobs"
else
    sql_root "DROP TABLE IF EXISTS \`${DB}\`.sessions, \`${DB}\`.cache, \`${DB}\`.cache_locks,
        \`${DB}\`.jobs, \`${DB}\`.job_batches, \`${DB}\`.failed_jobs"
fi

step "Registering the tenant"
# Idempotent: a database that is already a customer is updated instead of
# refused, and keeps its id, package, seats, billing and invoices.
# Checks for email addresses that already belong to another tenant itself, and
# creates the MySQL login for this database.
# Billing starts today unless a day was agreed; 'none' leaves it open, and then
# nothing is invoiced until someone fills it in on the subscription screen.
if [ -n "$BILLING_FROM" ]; then
    artisan tenant:setup-existing "$NAME" "$DB" --started-on="$BILLING_FROM"
else
    artisan tenant:setup-existing "$NAME" "$DB"
fi

step "Updating the schema"
artisan tenants:migrate

step "Copying the files"

TENANT_ID=""
if [ "$DRY" -eq 0 ]; then
    TENANT_ID=$(sql_root "SELECT id FROM ${LANDLORD_DB}.tenants
        WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'\$.tenancy_db_name'))='${DB}'")
fi

if [ -n "$TENANT_ID" ] && [ -d "$FROM/storage/app" ]; then
    TARGET="$PROJECT_ROOT/storage/tenant-${TENANT_ID}"

    run mkdir -p "$TARGET/public" "$TARGET/local"
    [ -d "$FROM/storage/app/public" ] && run cp -a "$FROM/storage/app/public/." "$TARGET/public/"
    [ -d "$FROM/storage/app/private" ] && run cp -a "$FROM/storage/app/private/." "$TARGET/local/"

    # Copied as root, so owned by root: without this the web server can read the
    # photos but writes nothing next to them, and the first upload fails.
    run chown -R "$APP_ACCOUNT" "$TARGET"

    green "  files under storage/tenant-${TENANT_ID}"
    info "  the originals in ${FROM}/storage stay where they are"
elif [ "$DRY" -eq 1 ]; then
    info "+ copy ${FROM}/storage/app into storage/tenant-<new id>"
else
    warn "  No files copied: ${FROM}/storage/app does not exist."
fi

if [ -n "$PACKAGE" ] && [ "$DRY" -eq 1 ]; then
    step "Package"
    info "+ artisan tenant:package <new id> ${PACKAGE}"
elif [ -n "$PACKAGE" ] && [ -n "$TENANT_ID" ]; then
    step "Package"

    # The package is agreed here, not at the source. Somebody who moved up a
    # package since the import would be put back on the old one by a re-run of
    # the same line -- which is the line that gets re-used, out of the history
    # or out of the note from last time.
    CURRENT=$(sql_root "SELECT COALESCE(package_key,'') FROM ${LANDLORD_DB}.tenants WHERE id='${TENANT_ID}'")

    if [ "$REFRESH" -eq 1 ] && [ -n "$CURRENT" ] && [ "$CURRENT" != "$PACKAGE" ]; then
        warn "  Left on ${CURRENT}, not put back to ${PACKAGE}: the package was changed here since the import.
  To change it anyway: php artisan tenant:package ${TENANT_ID} ${PACKAGE}"
    else
        artisan tenant:package "$TENANT_ID" "$PACKAGE"
    fi
fi

step "Done"
if [ "$DRY" -eq 1 ]; then
    info "Nothing was written (--dry-run). Run again without it to do the import."
else
    green "${NAME} is tenant ${TENANT_ID} on ${DB}"
    [ "$REFRESH" -eq 1 ] && info "Files removed at the source are still here: a refresh adds and overwrites,
it does not delete."
    info "Check with: php artisan tenancy:doctor"
fi
