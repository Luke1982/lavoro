#!/usr/bin/env bash
# Imports an installation that is not on this server, from what can be carried
# across: a dump of its database, its .env and an archive of its storage folder.
#
#   sudo scripts/tenancy/import-archive.sh \
#        --dump ~/whservice_crm.sql.gz --env ~/whservice.env --storage ~/storage.zip \
#        --name "WH Service" --slug whservice --package team \
#        --super-admin info@majorlabel.nl=info+whservice@majorlabel.nl [--dry-run]
#
# It makes those three look like an installation on this server -- the dump in
# a database of its own, the storage folder and the .env next to each other --
# and hands over to import-install.sh, which does the import. Every option that
# one takes is passed on, --refresh and --dry-run included.
#
# The dump may be plain or gzipped, and come from MySQL or MariaDB. The storage
# archive is a zip or tar(.gz) of the old storage folder, or a folder already
# unpacked; only storage/app is read from it.
#
# Leaves nothing of its own behind: the staging database lavoro_import_staging_<slug>
# and the staging folder go, whatever happens. The files passed in are not
# touched. Remove them once the customer works here: the .env holds their mail
# and SnelStart secrets, and the dump everything else.
set -euo pipefail

case "$0" in
    */*) SCRIPT_DIR="${0%/*}" ;;
    *)   SCRIPT_DIR="." ;;
esac
SCRIPT_DIR="$(cd "$SCRIPT_DIR" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

DUMP=""; ENV_FILE=""; STORAGE=""; SLUG=""; DRY=0
PASS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --dump) DUMP="$2"; shift 2 ;;
        --dump=*) DUMP="${1#*=}"; shift ;;
        --env) ENV_FILE="$2"; shift 2 ;;
        --env=*) ENV_FILE="${1#*=}"; shift ;;
        --storage) STORAGE="$2"; shift 2 ;;
        --storage=*) STORAGE="${1#*=}"; shift ;;
        --slug) SLUG="$2"; PASS+=(--slug "$2"); shift 2 ;;
        --slug=*) SLUG="${1#*=}"; PASS+=(--slug "$SLUG"); shift ;;
        --name|--package|--billing-from|--super-admin) PASS+=("$1" "$2"); shift 2 ;;
        --name=*|--package=*|--billing-from=*|--super-admin=*) PASS+=("$1"); shift ;;
        --dry-run) DRY=1; PASS+=(--dry-run); shift ;;
        --refresh) PASS+=(--refresh); shift ;;
        --from|--from=*) echo "--from is import-install.sh's; this one takes --dump, --env and --storage" >&2; exit 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

[ -n "$DUMP" ] && [ -n "$ENV_FILE" ] && [ -n "$SLUG" ] || {
    echo "--dump, --env and --slug are required (and --name, which import-install.sh asks for)" >&2; exit 2; }

[[ "$SLUG" =~ ^[a-z0-9_]+$ ]] || { echo "--slug takes lowercase letters, digits and underscores only" >&2; exit 2; }

# Absolute before anything else: elevating keeps the working directory, but the
# staging folder below is somewhere else, and so is whoever reads these later.
for VAR in DUMP ENV_FILE STORAGE; do
    [ -n "${!VAR}" ] || continue
    [ -e "${!VAR}" ] || { echo "Does not exist: ${!VAR}" >&2; exit 2; }
    printf -v "$VAR" '%s' "$(readlink -f -- "${!VAR}")"
done

# The same elevation as import-install.sh, for the same reasons: a database is
# created, and a privileged MySQL login is needed for it.
if [ "$(id -u)" -ne 0 ] && [ -z "${ADMIN_PASSWORD:-}${DEFAULTS_FILE:-}" ]; then
    ARGS=(--dump "$DUMP" --env "$ENV_FILE")
    [ -n "$STORAGE" ] && ARGS+=(--storage "$STORAGE")
    ARGS+=("${PASS[@]}")

    if sudo -n true 2>/dev/null; then
        echo "  elevating to root (creating a database needs it)"
        exec sudo -n -- "$0" "${ARGS[@]}"
    fi

    COMMAND="bash ${SCRIPT_DIR}/${0##*/}"
    for arg in "${ARGS[@]}"; do
        if [[ "$arg" =~ ^[A-Za-z0-9._/=:@+-]+$ ]]; then
            COMMAND="${COMMAND} ${arg}"
        else
            COMMAND="${COMMAND} '${arg//\'/\'\\\'\'}'"
        fi
    done

    printf 'This has to run as root: it creates a database.\n\n' >&2
    printf 'In a root shell:\n\n  %s\n' "$COMMAND" >&2
    exit 1
fi

# shellcheck source=scripts/tenancy/lib.sh
. "$SCRIPT_DIR/lib.sh"

preflight_common
require_commands find readlink
detect_client
ensure_admin_connection

step() { printf '\n== %s ==\n' "$1"; }

STAGING_DB="lavoro_import_staging_${SLUG}"

step "Preflight"

[ -f "$DUMP" ] && [ -r "$DUMP" ] || die "Cannot read the dump: $DUMP"
[ -f "$ENV_FILE" ] && [ -r "$ENV_FILE" ] || die "Cannot read the .env: $ENV_FILE"

case "$DUMP" in
    *.gz) require_commands gzip; gzip -t "$DUMP" || die "The dump is not a readable gzip file: $DUMP" ;;
    *.sql) ;;
    *) die "The dump has to be .sql or .sql.gz: $DUMP" ;;
esac

if [ -z "$STORAGE" ]; then
    warn "  No --storage: the customer comes across without photos, documents, logo or avatars."
elif [ -d "$STORAGE" ]; then
    :
else
    case "$STORAGE" in
        *.zip) require_commands unzip ;;
        *.tar|*.tar.gz|*.tgz) require_commands tar ;;
        *) die "--storage has to be a .zip, .tar, .tar.gz or a folder: $STORAGE" ;;
    esac
fi

# The name is this script's, so whatever already goes by it is a staging copy
# an interrupted run left behind.
if sql_root "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='${STAGING_DB}'" | grep -q .; then
    warn "  ${STAGING_DB} is left over from an earlier run; it is replaced"
fi

info "  dump:     ${DUMP}"
info "  .env:     ${ENV_FILE}"
info "  storage:  ${STORAGE:-(none)}"
info "  staging:  ${STAGING_DB}, removed again afterwards"
[ "$DRY" -eq 1 ] && info "  (dry run: the staging copy is made so the plan can be read off it; the customer is not touched)"

mkdir -p "$PROJECT_ROOT/storage/backups"
STAGE=$(mktemp -d "$PROJECT_ROOT/storage/backups/import-staging-${SLUG}-XXXXXX")
chmod 700 "$STAGE"

cleanup() {
    sql_root "DROP DATABASE IF EXISTS \`${STAGING_DB}\`" >/dev/null 2>&1 || true
    rm -rf "${STAGE:?}"
}
trap cleanup EXIT

step "Loading the dump into ${STAGING_DB}"

sql_root "DROP DATABASE IF EXISTS \`${STAGING_DB}\`"
sql_root "CREATE DATABASE \`${STAGING_DB}\` CHARACTER SET ${CHARSET} COLLATE ${COLLATION}"

# What a dump carries that would stop it, or send it somewhere else:
#  - MariaDB 10.11 and later open with a "sandbox mode" line that the MySQL
#    client refuses outright ("Unknown command '\-'");
#  - CREATE DATABASE and USE would load it into the source's own name instead;
#  - a DEFINER names an account that does not exist here;
#  - MariaDB 11's default collation does not exist in MySQL, nor in MariaDB 10.
# Never in the rows themselves: those lines start with INSERT.
sanitise() {
    sed -E \
        -e '1{/^\/\*M?!999999\\- enable the sandbox mode/d;}' \
        -e '/^CREATE DATABASE /d' \
        -e '/^USE `/d' \
        -e '/^INSERT /!s/ DEFINER=`[^`]*`@`[^`]*`//g' \
        -e '/^INSERT /!s/utf8mb4_uca1400_[a-z0-9_]+/utf8mb4_unicode_ci/g'
}

mapfile -t ARGS < <(admin_args)
case "$DUMP" in
    *.gz) gzip -dc "$DUMP" | sanitise | MYSQL_PWD="$ADMIN_PASSWORD" "$MYSQL_CLIENT" "${ARGS[@]}" "$STAGING_DB" ;;
    *)    sanitise < "$DUMP" | MYSQL_PWD="$ADMIN_PASSWORD" "$MYSQL_CLIENT" "${ARGS[@]}" "$STAGING_DB" ;;
esac

TABLES=$(sql_root "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${STAGING_DB}'")
[ "${TABLES:-0}" -gt 0 ] || die "The dump produced 0 tables in ${STAGING_DB}."

sql_root "SELECT 1 FROM information_schema.tables WHERE table_schema='${STAGING_DB}' AND table_name='users'" \
    | grep -q . || die "The dump has no users table. Is this a Lavoro installation?"

USERS=$(sql_root "SELECT COUNT(*) FROM \`${STAGING_DB}\`.users")
green "  ${TABLES} tables, ${USERS} users"

step "Unpacking the storage folder"

INSTALL="$STAGE/install"
mkdir -p "$INSTALL"

if [ -n "$STORAGE" ]; then
    if [ -d "$STORAGE" ]; then
        SOURCE="$STORAGE"
    else
        SOURCE="$STAGE/unpacked"
        mkdir -p "$SOURCE"

        case "$STORAGE" in
            *.zip) unzip -q "$STORAGE" -d "$SOURCE" ;;
            *)     tar -xf "$STORAGE" -C "$SOURCE" ;;
        esac

        # A link in an archive can point anywhere on this server, and the copy
        # would carry it into the customer's own folder, where /files serves it.
        LINKS=$(find "$SOURCE" -type l | wc -l)
        if [ "$LINKS" -gt 0 ]; then
            find "$SOURCE" -type l -delete
            warn "  removed ${LINKS} symbolic link(s) from the archive"
        fi
    fi

    # The folder that holds app/public or app/private: storage itself, or the
    # app folder's parent when only that was packed, or one level further down.
    APP=$(find "$SOURCE" -maxdepth 4 -type d -name app \
        \( -exec test -d '{}/public' \; -o -exec test -d '{}/private' \; \) -print | sort | head -n 1)

    [ -n "$APP" ] || die "No app/public or app/private in ${STORAGE}. Pack the old installation's storage folder."

    ln -s "${APP%/app}" "$INSTALL/storage"

    FILES=$(find "$APP" -type f -not -name .gitignore | wc -l)
    green "  ${FILES} file(s) under ${APP#"$SOURCE"/}"
else
    info "  nothing to unpack"
fi

# The old .env, pointing at the staging copy. import-install.sh reads the last
# DB_DATABASE line, and reads the mail, Microsoft 365 and SnelStart settings
# from the rest of it.
grep -v -E '^[[:space:]]*DB_DATABASE[[:space:]]*=' "$ENV_FILE" > "$INSTALL/.env" || true
printf '\nDB_DATABASE=%s\n' "$STAGING_DB" >> "$INSTALL/.env"
chmod 600 "$INSTALL/.env"

step "Handing over to import-install.sh"

# No exec: the staging copy is removed on the way out, also when the import
# fails, and only this shell can do that.
set +e
bash "$SCRIPT_DIR/import-install.sh" --from "$INSTALL" "${PASS[@]}"
STATUS=$?
set -e

if [ "$STATUS" -eq 0 ] && [ "$DRY" -eq 0 ]; then
    printf '\n'
    warn "The files you passed in are still where they were. Remove them once the
customer works here: the .env holds their mail and SnelStart secrets.
  ${DUMP}
  ${ENV_FILE}${STORAGE:+
  ${STORAGE}}"
fi

exit "$STATUS"
