#!/usr/bin/env bash
#
# One-time MySQL/MariaDB setup for multi-database tenancy.
#
# Creates the landlord database, the app account confined to it, and the
# passwordless provisioner account that owns the tenant namespace.
#
# Run once per environment, as root:
#
#   sudo scripts/tenancy/setup-mysql.sh
#   sudo scripts/tenancy/setup-mysql.sh --with-test --write-env
#
# Safe to re-run: every statement is idempotent and an existing app password
# is left alone unless --rotate-app-password is passed.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

WITH_TEST=0
WRITE_ENV=0
ROTATE_APP_PASSWORD=0
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage: sudo scripts/tenancy/setup-mysql.sh [options]

  --with-test             Also create the test account and test landlord database
  --write-env             Patch .env with the resulting credentials (backs it up first)
  --rotate-app-password   Force a new password for an existing lavoro_app account
  --generate-password     Skip the prompt and generate the lavoro_app password
  --admin-user=NAME       Privileged account to connect as (default: root)
  --defaults-file=PATH    my.cnf holding the admin credentials, instead of prompting
  --flavour=mysql|mariadb Skip server detection and target this flavour
  --dry-run               Print the SQL that would run, change nothing
  -h, --help              Show this help

Passwords:

  You are prompted for the lavoro_app password (Enter generates a strong one).
  If the admin account needs a password to connect, you are prompted for that
  too. Neither is ever passed on the command line, where it would show up in
  `ps` and in shell history.

  For unattended runs, set LAVORO_APP_PASSWORD and ADMIN_PASSWORD in the
  environment, or point --defaults-file at a 0600 my.cnf. With no terminal
  and no environment variable, the app password is generated.

The SQL differs between servers: MySQL uses IDENTIFIED WITH auth_socket,
MariaDB uses IDENTIFIED VIA unix_socket. Detection handles that, and
--flavour lets you review the other server's SQL from this machine:

  scripts/tenancy/setup-mysql.sh --dry-run --flavour=mariadb
USAGE
}

GENERATE_PASSWORD=0

while [ $# -gt 0 ]; do
    case "$1" in
        --with-test)           WITH_TEST=1 ;;
        --write-env)           WRITE_ENV=1 ;;
        --rotate-app-password) ROTATE_APP_PASSWORD=1 ;;
        --generate-password)   GENERATE_PASSWORD=1 ;;
        --admin-user=*)        ADMIN_USER="${1#*=}" ;;
        --defaults-file=*)     DEFAULTS_FILE="${1#*=}" ;;
        --flavour=*)           FORCE_FLAVOUR="${1#*=}" ;;
        --dry-run)             DRY_RUN=1 ;;
        -h|--help)             usage; exit 0 ;;
        *)                     die "Unknown option: $1" ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 1 ]; then
    # Reviewing the SQL should not require a running server or root.
    ALLOW_NO_CONNECTION=1
else
    require_root
fi

detect_client

if [ "$DRY_RUN" -eq 0 ]; then
    ensure_admin_connection
fi

detect_flavour

info "==> Detected database server"
print_flavour
info ""

if [ "$DRY_RUN" -eq 0 ]; then
    ensure_socket_plugin
fi

# ---------------------------------------------------------------------------
# App account password
# ---------------------------------------------------------------------------
#
# Only ask when a password is actually going to be set. On a re-run against an
# existing account there is nothing to choose, so prompting would just invite
# someone to type a password that never gets applied.

APP_USER_EXISTS="$(sql_root "SELECT COUNT(*) FROM mysql.user WHERE user = '${APP_USER}' AND host = '${APP_HOST}';" 2>/dev/null || echo 0)"

APP_PASSWORD=""
APP_PASSWORD_IS_NEW=0

if [ "$DRY_RUN" -eq 1 ]; then
    # A placeholder, not a generated secret: printing a real password that is
    # then thrown away invites someone to copy it out of the dry-run output.
    APP_PASSWORD="<prompted-or-generated-at-run-time>"
elif [ "$APP_USER_EXISTS" = "1" ] && [ "$ROTATE_APP_PASSWORD" -eq 0 ]; then
    APP_PASSWORD="$(env_value DB_PASSWORD)"
    if [ -z "$APP_PASSWORD" ]; then
        warn "  ${APP_USER}@${APP_HOST} already exists but no DB_PASSWORD was found in .env."
        warn "  Leaving its password untouched. Re-run with --rotate-app-password to set a new one."
    else
        info "  ${APP_USER}@${APP_HOST} exists; keeping its current password."
    fi
else
    info "==> Password for ${APP_USER}@${APP_HOST}"
    resolve_new_password APP_PASSWORD \
        "Password for ${APP_USER}" \
        LAVORO_APP_PASSWORD \
        "$GENERATE_PASSWORD"
    APP_PASSWORD_IS_NEW=1
    info ""
fi

# ---------------------------------------------------------------------------
# SQL
# ---------------------------------------------------------------------------
#
# Underscores are escaped in every GRANT pattern. An unescaped _ is a
# single-character wildcard, so `lavoro\_tenant\_%` matches only the tenant
# namespace, while lavoro_tenant_% would also match lavoroXtenantY.

build_sql() {
    cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${LANDLORD_DB}\`
    CHARACTER SET ${CHARSET} COLLATE ${COLLATION};

CREATE USER IF NOT EXISTS '${APP_USER}'@'${APP_HOST}' IDENTIFIED BY '${APP_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${LANDLORD_DB//_/\\_}\`.* TO '${APP_USER}'@'${APP_HOST}';

CREATE USER IF NOT EXISTS '${PROV_USER}'@'${PROV_HOST}' ${IDENTIFIED_CLAUSE};
GRANT ALL PRIVILEGES ON \`${TENANT_PREFIX//_/\\_}%\`.* TO '${PROV_USER}'@'${PROV_HOST}' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`${LANDLORD_DB//_/\\_}\`.* TO '${PROV_USER}'@'${PROV_HOST}';
GRANT CREATE USER ON *.* TO '${PROV_USER}'@'${PROV_HOST}';

FLUSH PRIVILEGES;
SQL
}

build_rotate_sql() {
    cat <<SQL
ALTER USER '${APP_USER}'@'${APP_HOST}' IDENTIFIED BY '${APP_PASSWORD}';
FLUSH PRIVILEGES;
SQL
}

build_test_sql() {
    cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\`
    CHARACTER SET ${CHARSET} COLLATE ${COLLATION};

CREATE USER IF NOT EXISTS '${TEST_USER}'@'${APP_HOST}' IDENTIFIED BY '${TEST_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${TEST_PREFIX//_/\\_}%\`.* TO '${TEST_USER}'@'${APP_HOST}';

FLUSH PRIVILEGES;
SQL
}

if [ "$DRY_RUN" -eq 1 ]; then
    info "==> SQL that would run"
    info ""
    build_sql
    if [ "$APP_USER_EXISTS" = "1" ] && [ "$ROTATE_APP_PASSWORD" -eq 1 ]; then
        build_rotate_sql
    fi
    [ "$WITH_TEST" -eq 1 ] && build_test_sql
    exit 0
fi

# ---------------------------------------------------------------------------
# Linux user for the provisioner
# ---------------------------------------------------------------------------
#
# Socket authentication maps a MySQL account to an operating-system identity,
# so the Linux user has to exist before the MySQL account is of any use.

info "==> Creating the ${PROV_USER} Linux user"
if id -u "$PROV_USER" >/dev/null 2>&1; then
    info "  Already exists."
else
    adduser --system --group --no-create-home "$PROV_USER"
    green "  Created."
fi
info ""

# ---------------------------------------------------------------------------
# Apply
# ---------------------------------------------------------------------------

info "==> Creating databases and accounts"
build_sql | sql_root_stdin

if [ "$APP_USER_EXISTS" = "1" ] && [ "$ROTATE_APP_PASSWORD" -eq 1 ]; then
    build_rotate_sql | sql_root_stdin
    warn "  Rotated the ${APP_USER} password. Update .env and restart the queue workers."
fi

green "  ${LANDLORD_DB} ready."
green "  ${APP_USER}@${APP_HOST} granted on ${LANDLORD_DB} only."
green "  ${PROV_USER}@${PROV_HOST} granted on ${TENANT_PREFIX}% + ${LANDLORD_DB}, with CREATE USER."
info ""

if [ "$WITH_TEST" -eq 1 ]; then
    info "==> Creating the test account"
    build_test_sql | sql_root_stdin
    green "  ${TEST_USER}@${APP_HOST} granted on ${TEST_PREFIX}% only (covers ${TEST_DB} and ${TEST_PREFIX}tenant_*)."
    info ""
fi

# ---------------------------------------------------------------------------
# .env
# ---------------------------------------------------------------------------

ENV_BLOCK="DB_CONNECTION=mysql
DB_HOST=${APP_HOST}
DB_PORT=3306
DB_DATABASE=${LANDLORD_DB}
DB_USERNAME=${APP_USER}
DB_PASSWORD=\"${APP_PASSWORD}\"
DB_SOCKET=/var/run/mysqld/mysqld.sock
SESSION_CONNECTION=central"

if [ "$WRITE_ENV" -eq 1 ]; then
    ENV_FILE="$PROJECT_ROOT/.env"
    [ -f "$ENV_FILE" ] || die ".env not found at $ENV_FILE"

    BACKUP="$ENV_FILE.backup-$(date +%Y-%m-%d_%H-%M-%S)"
    cp "$ENV_FILE" "$BACKUP"
    info "==> Patching .env (backup at $(basename "$BACKUP"))"

    while IFS= read -r line; do
        key="${line%%=*}"
        value="${line#*=}"
        if grep -qE "^${key}=" "$ENV_FILE"; then
            # The value can contain / and &, so use a delimiter that cannot
            # appear in an env key and escape the replacement.
            escaped="$(printf '%s' "$value" | sed -e 's/[\&|]/\\&/g')"
            sed -i "s|^${key}=.*|${key}=${escaped}|" "$ENV_FILE"
        else
            printf '%s\n' "$line" >> "$ENV_FILE"
        fi
    done <<< "$ENV_BLOCK"

    green "  .env updated."
    if [ "$APP_PASSWORD_IS_NEW" -eq 0 ] && [ -z "$APP_PASSWORD" ]; then
        warn "  DB_PASSWORD was left as-is; the existing account's password is unknown to this script."
    fi
else
    info "==> Add this to .env"
    info ""
    printf '%s\n' "$ENV_BLOCK"
fi

info ""
info "Next: scripts/tenancy/verify-mysql.sh"
