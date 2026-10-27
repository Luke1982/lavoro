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

PASSED=0
SKIPPED=0
NOT_APPLICABLE=0
FAILED=0
SCRATCH_DB="${TENANT_PREFIX}verify_$$"
OUTSIDE_DB="lavoro_notatenant_$$"
SCRATCH_USER="lavoro_verify_$$"

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
# Two kinds of skipping, and the difference matters. Not being able to check
# something leaves a hole; checking something that does not exist yet does not.
# Only the first makes the verdict incomplete.
skip() { warn  "  SKIP  $*"; SKIPPED=$((SKIPPED + 1)); }
skip_na() { warn  "  SKIP  $*"; NOT_APPLICABLE=$((NOT_APPLICABLE + 1)); }

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
        if [ "$DB_FLAVOUR" = "mariadb" ]; then
            fail "${SOCKET_PLUGIN} is not loaded — the provisioner account cannot authenticate.
          On MariaDB it is normally built in and active without installing anything;
          'INSTALL SONAME' fails there because there is no separate library file."
        else
            fail "${SOCKET_PLUGIN} is not loaded — the provisioner account cannot authenticate.
          Switch it on with: INSTALL PLUGIN auth_socket SONAME 'auth_socket.so';"
        fi
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
    # Everything in this block runs as the provisioner itself. One place where
    # that is written, instead of the same line five times with another command.
    as_provisioner() {
        sudo -u "$PROV_USER" "$MYSQL_CLIENT" --protocol=socket -e "$1" 2>&1
    }

    CURRENT="$(as_provisioner "SELECT current_user();" | tail -1 || true)"

    if [ "$CURRENT" = "${PROV_USER}@${PROV_HOST}" ]; then
        pass "authenticates as ${PROV_USER}@${PROV_HOST} without a password"
    else
        fail "expected ${PROV_USER}@${PROV_HOST}, got '${CURRENT:-<connection failed>}'"
    fi

    # Whatever goes wrong, the probe database and the probe account go away
    # again. If they stay, every following check carries the previous one's
    # leftovers -- and a checking script of all things should leave nothing.
    clean_probe() {
        as_provisioner "DROP USER IF EXISTS \`${SCRATCH_USER}\`@\`%\`;" >/dev/null 2>&1 || true
        as_provisioner "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`;" >/dev/null 2>&1 || true
    }

    trap clean_probe EXIT

    # Clean up leftovers from an earlier run. A check that breaks off halfway
    # leaves its probe database behind, and that then shows up as "database
    # without a tenant" in the doctor -- a finding about a problem this script
    # heeft gemaakt.
    if [ "$HAVE_ROOT_DB" -eq 1 ]; then
        while IFS= read -r statement; do
            [ -n "$statement" ] && sql_root "$statement" >/dev/null 2>&1
        done < <(sql_root "
            SELECT CONCAT('DROP DATABASE IF EXISTS \`', schema_name, '\`;')
              FROM information_schema.schemata
             WHERE schema_name LIKE '${TENANT_PREFIX}verify\\_%'
             UNION ALL
            SELECT CONCAT('DROP USER IF EXISTS \`', user, '\`@\`', host, '\`;')
              FROM mysql.user
             WHERE user LIKE 'lavoro\\_verify\\_%';" 2>/dev/null || true)
    fi

    if as_provisioner "CREATE DATABASE \`${SCRATCH_DB}\`;" >/dev/null 2>&1; then
        pass "can create a database inside the ${TENANT_PREFIX} namespace"

        # Creating a database is half the work. Every customer also gets a
        # MySQL login of its own that may only reach that one database. Handing
        # that out is done by a procedure running as root, because a GRANT with
        # a database name in it cannot be satisfied by the wildcard this account
        # holds. Checking only the creating let exactly this break in
        # production, leaving a half created customer behind.
        #
        # '|| true' belongs here: without it a failed statement under 'set -e'
        # takes the whole script with it, and then there is nothing left to
        # report.
        USER_ERROR="$(as_provisioner "CREATE USER \`${SCRATCH_USER}\`@\`%\` IDENTIFIED BY 'verify-only';" || true)"

        if [ -n "$USER_ERROR" ]; then
            fail "cannot create a MySQL account for a tenant:
        ${USER_ERROR}"
        else
            GRANT_ERROR="$(as_provisioner "CALL \`${ADMIN_DB}\`.\`${GRANT_PROCEDURE}\`('${SCRATCH_DB}', '${SCRATCH_USER}');" || true)"

            if [ -z "$GRANT_ERROR" ]; then
                pass "can give a tenant login rights on its own database"
            else
                fail "cannot give a tenant login its rights, so creating a tenant fails halfway:
        ${GRANT_ERROR}
        Re-run: sudo scripts/tenancy/setup-mysql.sh"
            fi

            # The procedure is the only hole in the separation, so that hole
            # has to be as narrow as intended: outside the customer namespace it
            # should refuse. If it does not, the provisioner can hand out rights
            # on every database there is through this road.
            if as_provisioner "CALL \`${ADMIN_DB}\`.\`${GRANT_PROCEDURE}\`('${LANDLORD_DB}', '${SCRATCH_USER}');" >/dev/null 2>&1; then
                fail "the grant procedure accepted ${LANDLORD_DB} — it must refuse anything
        outside the ${TENANT_PREFIX} namespace"
            else
                pass "the grant procedure refuses databases outside the ${TENANT_PREFIX} namespace"
            fi
        fi

    else
        fail "cannot create ${SCRATCH_DB} — tenant creation will fail"
    fi

    # The namespace is the whole point: everything outside it should be
    # unreachable, so that a mistake in provisioning cannot touch another
    # database.
    if as_provisioner "CREATE DATABASE \`${OUTSIDE_DB}\`;" >/dev/null 2>&1; then
        fail "created ${OUTSIDE_DB} outside the tenant namespace — the grant is too wide"
        as_provisioner "DROP DATABASE \`${OUTSIDE_DB}\`;" >/dev/null 2>&1 || true
    else
        pass "refused to create a database outside the ${TENANT_PREFIX} namespace"
    fi

    clean_probe
    trap - EXIT
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
    skip_na "no tenant accounts yet (expected before the first tenant:create)"
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
# Leaving a trace
# ---------------------------------------------------------------------------
#
# This script needs root, the doctor runs as the site's account. So it can
# never reach this itself and had to say "cannot be seen from here" until now.
# By writing the verdict down here the doctor does know what came out,
# and when.
#
# Only a complete run is recorded: without root this script skips most of it,
# and that is not approval.

record_outcome() {
    local file="$PROJECT_ROOT/storage/app/tenancy-privileges.json"

    [ -d "${file%/*}" ] || return 0

    # Half a run must not overwrite a whole one. Without root most of it is
    # skipped, and that would replace the verdict of an earlier complete check
    # with something that proves nothing.
    #
    # Checks that do not apply are not counted here: that there are no customer
    # accounts yet is not a hole in the check, that is the situation.
    if [ "$SKIPPED" -ne 0 ]; then
        return 0
    fi

    printf '{"checked_at":"%s","passed":%d,"failed":%d,"skipped":%d,"not_applicable":%d,"by":"%s"}\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$PASSED" "$FAILED" "$SKIPPED" "$NOT_APPLICABLE" "$(id -un)" \
        > "$file" 2>/dev/null || return 0

    chmod 0644 "$file" 2>/dev/null || true
}

record_outcome

info ""

# Name skipped checks separately. "All 4 checks passed" under a list where half
# was skipped reads as approval, while precisely the checks that need root --
# the rights of the accounts -- have not
# gedaan.
if [ "$FAILED" -eq 0 ] && [ "$SKIPPED" -eq 0 ]; then
    if [ "$NOT_APPLICABLE" -eq 0 ]; then
        green "All ${PASSED} checks passed."
    else
        green "All ${PASSED} applicable checks passed (${NOT_APPLICABLE} did not apply yet)."
    fi
    exit 0
fi

if [ "$FAILED" -eq 0 ]; then
    warn "${PASSED} passed, ${SKIPPED} could not be checked -- this was not a full check."
    warn "Re-run with sudo to include the ones that need a privileged connection."
    exit 0
fi

red "${FAILED} check(s) failed, ${PASSED} passed, ${SKIPPED} not checked."
exit 1
