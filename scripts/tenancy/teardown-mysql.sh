#!/usr/bin/env bash
#
# Drops everything setup-mysql.sh created, plus every tenant database and
# tenant MySQL account. For iterating on a local install — it destroys all
# tenant data and cannot be undone.
#
#   sudo scripts/tenancy/teardown-mysql.sh --yes-really
#
# Refuses to run unless APP_ENV=local in .env.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

CONFIRMED=0
DROP_TEST=0

usage() {
    cat <<'USAGE'
Usage: sudo scripts/tenancy/teardown-mysql.sh --yes-really [--with-test]

  --yes-really   Required. Confirms you intend to destroy every tenant database.
  --with-test    Also drop the test account and test databases.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --yes-really) CONFIRMED=1 ;;
        --with-test)  DROP_TEST=1 ;;
        -h|--help)    usage; exit 0 ;;
        *)            die "Unknown option: $1" ;;
    esac
    shift
done

require_root

APP_ENV="$(env_value APP_ENV)"
if [ "$APP_ENV" != "local" ]; then
    die "Refusing to run: APP_ENV is '${APP_ENV:-unset}', not 'local'. This script destroys tenant data."
fi

if [ "$CONFIRMED" -ne 1 ]; then
    usage
    die "Refusing to run without --yes-really."
fi

detect_client
detect_flavour

TENANT_DBS="$(sql_root "SELECT schema_name FROM information_schema.schemata
                        WHERE schema_name LIKE '${TENANT_PREFIX}%';" 2>/dev/null || true)"
TENANT_USERS="$(sql_root "SELECT user FROM mysql.user WHERE user LIKE '${TENANT_PREFIX}%';" 2>/dev/null || true)"

info "==> About to drop"
info "  Landlord database: ${LANDLORD_DB}"
info "  Tenant databases:  $(printf '%s' "${TENANT_DBS:-none}" | tr '\n' ' ')"
info "  Tenant accounts:   $(printf '%s' "${TENANT_USERS:-none}" | tr '\n' ' ')"
info "  Accounts:          ${APP_USER}@${APP_HOST}, ${PROV_USER}@${PROV_HOST}"
[ "$DROP_TEST" -eq 1 ] && info "  Test:              ${TEST_DB}, ${TEST_USER}@${APP_HOST}, ${TEST_PREFIX}*"
info ""

read -r -p "Type the word 'destroy' to continue: " answer
[ "$answer" = "destroy" ] || die "Aborted."

if [ -n "$TENANT_DBS" ]; then
    while IFS= read -r db; do
        [ -n "$db" ] || continue
        sql_root "DROP DATABASE IF EXISTS \`${db}\`;"
        info "  Dropped ${db}"
    done <<< "$TENANT_DBS"
fi

if [ -n "$TENANT_USERS" ]; then
    while IFS= read -r tenant_user; do
        [ -n "$tenant_user" ] || continue
        sql_root "DROP USER IF EXISTS '${tenant_user}'@'%';"
        info "  Dropped account ${tenant_user}"
    done <<< "$TENANT_USERS"
fi

sql_root "DROP DATABASE IF EXISTS \`${LANDLORD_DB}\`;"
sql_root "DROP USER IF EXISTS '${APP_USER}'@'${APP_HOST}';"
sql_root "DROP USER IF EXISTS '${PROV_USER}'@'${PROV_HOST}';"

if [ "$DROP_TEST" -eq 1 ]; then
    TEST_DBS="$(sql_root "SELECT schema_name FROM information_schema.schemata
                          WHERE schema_name LIKE '${TEST_PREFIX}%';" 2>/dev/null || true)"
    if [ -n "$TEST_DBS" ]; then
        while IFS= read -r db; do
            [ -n "$db" ] || continue
            sql_root "DROP DATABASE IF EXISTS \`${db}\`;"
            info "  Dropped ${db}"
        done <<< "$TEST_DBS"
    fi
    sql_root "DROP USER IF EXISTS '${TEST_USER}'@'${APP_HOST}';"
fi

sql_root "FLUSH PRIVILEGES;"

info ""
green "Done. The ${PROV_USER} Linux user was left in place — remove it with:"
info "  sudo deluser --system ${PROV_USER}"
