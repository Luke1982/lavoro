# Shared helpers for the tenancy MySQL/MariaDB scripts.
# Sourced, never executed directly.

LANDLORD_DB="${LANDLORD_DB:-lavoro_landlord}"
TENANT_PREFIX="${TENANT_PREFIX:-lavoro_tenant_}"
APP_USER="${APP_USER:-lavoro_app}"
APP_HOST="${APP_HOST:-127.0.0.1}"
PROV_USER="${PROV_USER:-lavoro_provisioner}"
PROV_HOST="${PROV_HOST:-localhost}"
TEST_USER="${TEST_USER:-lavoro_test}"
TEST_DB="${TEST_DB:-lavoro_test_landlord}"
TEST_PREFIX="${TEST_PREFIX:-lavoro_test_}"

COLLATION="utf8mb4_unicode_ci"
CHARSET="utf8mb4"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
warn()  { printf '\033[33m%s\033[0m\n' "$*"; }
info()  { printf '%s\n' "$*"; }

die() { red "$*" >&2; exit 1; }

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        die "This script must run as root (it creates a Linux user and MySQL accounts). Try: sudo $0"
    fi
}

# Prefer the mysql client, fall back to MariaDB's own binary name. Newer
# MariaDB installs ship `mariadb` and may drop the `mysql` symlink entirely.
detect_client() {
    if command -v mysql >/dev/null 2>&1; then
        MYSQL_CLIENT="mysql"
    elif command -v mariadb >/dev/null 2>&1; then
        MYSQL_CLIENT="mariadb"
    else
        die "Neither 'mysql' nor 'mariadb' client found on PATH."
    fi
}

# Root connects over the local socket. On both Ubuntu MySQL and MariaDB the
# root account authenticates by OS identity, so no password is needed; set
# MYSQL_PWD in the environment if this server's root has one.
sql_root() {
    "$MYSQL_CLIENT" --protocol=socket -N -B -e "$1"
}

sql_root_quiet() {
    "$MYSQL_CLIENT" --protocol=socket -N -B -e "$1" >/dev/null 2>&1
}

# The two servers differ in three ways that matter here: the plugin name, the
# CREATE USER syntax that selects it, and the SONAME used to install it.
set_flavour() {
    case "$1" in
        mariadb)
            DB_FLAVOUR="mariadb"
            SOCKET_PLUGIN="unix_socket"
            # MariaDB's canonical syntax is IDENTIFIED VIA <plugin>. It accepts
            # IDENTIFIED WITH only in some versions, so do not rely on it.
            IDENTIFIED_CLAUSE="IDENTIFIED VIA ${SOCKET_PLUGIN}"
            PLUGIN_SONAME="auth_socket"
            ;;
        mysql)
            DB_FLAVOUR="mysql"
            SOCKET_PLUGIN="auth_socket"
            IDENTIFIED_CLAUSE="IDENTIFIED WITH ${SOCKET_PLUGIN}"
            PLUGIN_SONAME="auth_socket.so"
            ;;
        *)
            die "Unknown flavour '$1'. Expected mysql or mariadb."
            ;;
    esac
}

# MariaDB reports itself in VERSION() ("11.4.2-MariaDB") and in
# @@version_comment. MySQL never contains the string, so one check settles it.
#
# FORCE_FLAVOUR skips detection entirely, so the SQL for either server can be
# reviewed from a machine running the other one.
detect_flavour() {
    if [ -n "${FORCE_FLAVOUR:-}" ]; then
        set_flavour "$FORCE_FLAVOUR"
        DB_VERSION="(detection skipped, --flavour=${FORCE_FLAVOUR})"
        return 0
    fi

    local version
    if ! version="$(sql_root "SELECT CONCAT(VERSION(), ' ', @@version_comment);" 2>/dev/null)"; then
        if [ "${ALLOW_NO_CONNECTION:-0}" = "1" ]; then
            set_flavour mysql
            DB_VERSION="(could not connect; assuming mysql — pass --flavour=mariadb to see the MariaDB variant)"
            return 0
        fi
        die "Could not connect to the database as root. Is the server running, and does root use socket auth?"
    fi

    DB_VERSION="$version"

    if printf '%s' "$version" | grep -qi mariadb; then
        set_flavour mariadb
    else
        set_flavour mysql
    fi
}

# The socket plugin is compiled in but not always loaded. Installing it is
# idempotent, and the SONAME differs between the two servers.
ensure_socket_plugin() {
    local active
    active="$(sql_root "SELECT COUNT(*) FROM information_schema.plugins
                        WHERE plugin_name = '${SOCKET_PLUGIN}' AND plugin_status = 'ACTIVE';")"

    if [ "$active" = "1" ]; then
        return 0
    fi

    info "  ${SOCKET_PLUGIN} not loaded; installing it."

    if [ "$DB_FLAVOUR" = "mariadb" ]; then
        sql_root_quiet "INSTALL SONAME '${PLUGIN_SONAME}';" || true
    else
        sql_root_quiet "INSTALL PLUGIN ${SOCKET_PLUGIN} SONAME '${PLUGIN_SONAME}';" || true
    fi

    active="$(sql_root "SELECT COUNT(*) FROM information_schema.plugins
                        WHERE plugin_name = '${SOCKET_PLUGIN}' AND plugin_status = 'ACTIVE';")"

    [ "$active" = "1" ] || die "Could not activate ${SOCKET_PLUGIN}. Socket authentication is required for the provisioner account."
}

# Reads a key from the project's .env without sourcing it (values may contain
# spaces, #, quotes). Prints nothing when absent.
env_value() {
    local key="$1" file="${2:-$PROJECT_ROOT/.env}" line
    [ -f "$file" ] || return 0
    line="$(grep -E "^${key}=" "$file" | tail -1)" || return 0
    line="${line#*=}"
    line="${line%\"}"
    line="${line#\"}"
    printf '%s' "$line"
}

generate_password() {
    # cut rather than head: head closes the pipe early, which trips pipefail.
    openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32
}

print_flavour() {
    info "  Server:  ${DB_VERSION}"
    info "  Flavour: ${DB_FLAVOUR}"
    info "  Socket:  ${SOCKET_PLUGIN} (${IDENTIFIED_CLAUSE})"
}
