#!/usr/bin/env bash
#
# Asserts that the tenancy database accounts are scoped the way the design
# assumes. Every check is a claim the application's isolation depends on, so
# a failure here is a real finding, not a warning.
#
#   sudo scripts/tenancy/verify-mysql.sh
#
# Run it with sudo for the full set. Without root it still checks everything
# reachable with the app credentials from .env, and skips the rest rather
# than reporting a false pass.
#
# Exits non-zero if any check fails, so it can gate a deploy.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

PASSED=0
FAILED=0
SCRATCH_DB="${TENANT_PREFIX}verify_$$"
OUTSIDE_DB="lavoro_notatenant_$$"

while [ $# -gt 0 ]; do
    case "$1" in
        --admin-user=*)    ADMIN_USER="${1#*=}" ;;
        --defaults-file=*) DEFAULTS_FILE="${1#*=}" ;;
        -h|--help)
            cat <<'USAGE'
Usage: sudo scripts/tenancy/verify-mysql.sh [--admin-user=NAME] [--defaults-file=PATH]

Set ADMIN_PASSWORD in the environment for an unattended run; otherwise you are
prompted if the admin account needs a password. Without a privileged connection
the server-side checks are skipped rather than reported as passing.
USAGE
            exit 0
            ;;
        *) die "Unknown option: $1" ;;
    esac
    shift
done

pass() { green "  PASS  $*"; PASSED=$((PASSED + 1)); }
fail() { red   "  FAIL  $*"; FAILED=$((FAILED + 1)); }
skip() { warn  "  SKIP  $*"; }

detect_client

# Missing root access must not abort the run: the app-account checks below
# need no privileges, and silently skipping is safer than a false pass. Prompt
# for a password only when there is a terminal and some chance of succeeding —
# this script is meant to be usable as a non-interactive deploy gate.
HAVE_ROOT_DB=1
if sql_root_quiet "SELECT 1;"; then
    detect_flavour
elif [ "$(id -u)" -eq 0 ] && have_tty; then
    ensure_admin_connection
    detect_flavour
else
    HAVE_ROOT_DB=0
    ALLOW_NO_CONNECTION=1
    detect_flavour
fi

info "==> Server"
print_flavour
[ "$HAVE_ROOT_DB" -eq 1 ] || warn "  No privileged connection — server-side checks will be skipped. Re-run with sudo."
info ""

APP_PASSWORD="$(env_value DB_PASSWORD)"
APP_DB="$(env_value DB_DATABASE)"

# ---------------------------------------------------------------------------
# Socket plugin
# ---------------------------------------------------------------------------

info "==> Socket authentication"

if [ "$HAVE_ROOT_DB" -eq 0 ]; then
    skip "cannot read information_schema.plugins without a privileged connection"
else
    ACTIVE="$(sql_root "SELECT COUNT(*) FROM information_schema.plugins
                        WHERE plugin_name = '${SOCKET_PLUGIN}' AND plugin_status = 'ACTIVE';" 2>/dev/null || echo 0)"
    if [ "$ACTIVE" = "1" ]; then
        pass "${SOCKET_PLUGIN} is loaded"
    else
        fail "${SOCKET_PLUGIN} is not loaded — the provisioner account cannot authenticate"
    fi
fi

# ---------------------------------------------------------------------------
# Provisioner: bound to an OS identity, owns the tenant namespace only
# ---------------------------------------------------------------------------

info ""
info "==> Provisioner (${PROV_USER}@${PROV_HOST})"

if ! id -u "$PROV_USER" >/dev/null 2>&1; then
    fail "Linux user ${PROV_USER} does not exist"
elif [ "$(id -u)" -ne 0 ]; then
    skip "provisioner checks need root to switch user — re-run with sudo"
else
    CURRENT="$(sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket -N -B -e "SELECT current_user();" 2>/dev/null || true)"
    if [ "$CURRENT" = "${PROV_USER}@${PROV_HOST}" ]; then
        pass "authenticates as ${PROV_USER}@${PROV_HOST} without a password"
    else
        fail "expected ${PROV_USER}@${PROV_HOST}, got '${CURRENT:-<connection failed>}'"
    fi

    if sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket \
        -e "CREATE DATABASE \`${SCRATCH_DB}\`;" >/dev/null 2>&1; then
        pass "can create a database inside the ${TENANT_PREFIX} namespace"
        sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket \
            -e "DROP DATABASE \`${SCRATCH_DB}\`;" >/dev/null 2>&1 || true
    else
        fail "cannot create ${SCRATCH_DB} — tenant creation will fail"
    fi

    # The point of the namespace: everything outside it is off limits, so a
    # provisioning mistake cannot reach a pre-tenancy or unrelated database.
    if sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket \
        -e "CREATE DATABASE \`${OUTSIDE_DB}\`;" >/dev/null 2>&1; then
        fail "created ${OUTSIDE_DB} outside the tenant namespace — the grant is too wide"
        sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket \
            -e "DROP DATABASE \`${OUTSIDE_DB}\`;" >/dev/null 2>&1 || true
    else
        pass "refused to create a database outside the ${TENANT_PREFIX} namespace"
    fi
fi

# A password must not work for this account from any OS user. Only meaningful
# once the account exists — otherwise it "passes" because there is nothing to
# connect to, which is the kind of false assurance this script must not give.
if [ "$HAVE_ROOT_DB" -eq 0 ]; then
    skip "cannot confirm ${PROV_USER} exists, so the TCP check would be meaningless"
else
    PROV_EXISTS="$(sql_root "SELECT COUNT(*) FROM mysql.user WHERE user = '${PROV_USER}';" 2>/dev/null || echo 0)"
    if [ "$PROV_EXISTS" = "0" ]; then
        fail "${PROV_USER} MySQL account does not exist"
    elif "$MYSQL_CLIENT" -u "$PROV_USER" --protocol=tcp -h 127.0.0.1 -e "SELECT 1;" >/dev/null 2>&1; then
        fail "${PROV_USER} is reachable over TCP — it should be socket-only"
    else
        pass "not reachable over TCP (socket-only, as intended)"
    fi
fi

# ---------------------------------------------------------------------------
# App account: landlord database and nothing else
# ---------------------------------------------------------------------------

info ""
info "==> App account (${APP_USER}@${APP_HOST})"

if [ -z "$APP_PASSWORD" ]; then
    skip "DB_PASSWORD not found in .env — cannot test the app account"
else
    app_sql() {
        MYSQL_PWD="$APP_PASSWORD" "$MYSQL_CLIENT" -u "$APP_USER" -h "$APP_HOST" --protocol=tcp -N -B -e "$1"
    }

    if app_sql "SELECT 1;" >/dev/null 2>&1; then
        pass "can connect"
        APP_CONNECTED=1
    else
        fail "cannot connect — check DB_PASSWORD in .env"
        APP_CONNECTED=0
    fi

    # Every check below is a negative assertion ("cannot see X", "cannot do
    # Y"). A closed connection satisfies all of them trivially, so they are
    # only run once the connection itself is known good.
    if [ "$APP_CONNECTED" -eq 0 ]; then
        skip "remaining app-account checks — they would pass simply because the connection failed"
    else
        VISIBLE="$(app_sql "SHOW DATABASES;" 2>/dev/null | grep -v '^information_schema$' | grep -v '^performance_schema$' || true)"
        if [ "$VISIBLE" = "$LANDLORD_DB" ]; then
            pass "sees only ${LANDLORD_DB}"
        else
            fail "sees more than the landlord database: $(printf '%s' "$VISIBLE" | tr '\n' ' ')"
        fi

        if app_sql "CREATE DATABASE \`${TENANT_PREFIX}nope\`;" >/dev/null 2>&1; then
            fail "created ${TENANT_PREFIX}nope — the app account can provision, which it must not"
            sql_root "DROP DATABASE \`${TENANT_PREFIX}nope\`;" >/dev/null 2>&1 || true
        else
            pass "cannot create databases"
        fi
    fi

    if [ -n "$APP_DB" ] && [ "$APP_DB" != "$LANDLORD_DB" ]; then
        fail ".env DB_DATABASE is '${APP_DB}', expected '${LANDLORD_DB}'"
    else
        pass ".env points at ${LANDLORD_DB}"
    fi
fi

# ---------------------------------------------------------------------------
# Tenant accounts, if any exist yet
# ---------------------------------------------------------------------------

info ""
info "==> Tenant accounts"

if [ "$HAVE_ROOT_DB" -eq 1 ]; then
    TENANT_USERS="$(sql_root "SELECT user FROM mysql.user WHERE user LIKE '${TENANT_PREFIX}%';" 2>/dev/null || true)"
else
    TENANT_USERS=""
fi

if [ "$HAVE_ROOT_DB" -eq 0 ]; then
    skip "cannot read mysql.user without a privileged connection"
elif [ -z "$TENANT_USERS" ]; then
    skip "no tenant accounts yet (expected before the first tenant:create)"
else
    while IFS= read -r tenant_user; do
        [ -n "$tenant_user" ] || continue
        GRANTS="$(sql_root "SHOW GRANTS FOR '${tenant_user}'@'%';" 2>/dev/null || true)"
        if printf '%s' "$GRANTS" | grep -q 'ON \*\.\*'; then
            fail "${tenant_user} holds a server-wide grant"
        elif printf '%s' "$GRANTS" | grep -qi 'WITH GRANT OPTION'; then
            fail "${tenant_user} can grant privileges to others"
        else
            pass "${tenant_user} is confined to its own database"
        fi
    done <<< "$TENANT_USERS"
fi

# ---------------------------------------------------------------------------

info ""
if [ "$FAILED" -eq 0 ]; then
    green "All ${PASSED} checks passed."
    exit 0
fi

red "${FAILED} check(s) failed, ${PASSED} passed."
exit 1
