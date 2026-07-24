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
# Deliberately fixed and deliberately weak: phpunit.xml hardcodes the same
# value, and the account is granted only on lavoro_test_%. Prompting for it
# would mean editing phpunit.xml on every machine and in CI.
TEST_PASSWORD="${TEST_PASSWORD:-lavoro_test}"

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

ADMIN_USER="${ADMIN_USER:-root}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
DEFAULTS_FILE="${DEFAULTS_FILE:-}"

# Builds the client arguments for the privileged connection. Order matters:
# --defaults-file must come first, and it is the only option that keeps the
# password off both the command line and the process environment.
admin_args() {
    if [ -n "$DEFAULTS_FILE" ]; then
        printf '%s\n' "--defaults-file=$DEFAULTS_FILE" "--protocol=socket" "-u" "$ADMIN_USER"
    else
        printf '%s\n' "--protocol=socket" "-u" "$ADMIN_USER"
    fi
}

# The privileged connection. On Ubuntu MySQL and on MariaDB, root authenticates
# by OS identity over the socket and needs no password — but that is a packaging
# default, not a guarantee, so ensure_admin_connection() below prompts when it
# is not the case. MYSQL_PWD is used rather than -p because a password on the
# command line is visible in `ps` to every user on the box.
sql_root() {
    local args
    mapfile -t args < <(admin_args)
    MYSQL_PWD="$ADMIN_PASSWORD" "$MYSQL_CLIENT" "${args[@]}" -N -B -e "$1"
}

sql_root_quiet() {
    sql_root "$1" >/dev/null 2>&1
}

sql_root_stdin() {
    local args
    mapfile -t args < <(admin_args)
    MYSQL_PWD="$ADMIN_PASSWORD" "$MYSQL_CLIENT" "${args[@]}"
}

# Reads a secret without echoing it, twice, and requires the two to match.
# Prints nothing; assigns to the named variable.
prompt_password() {
    local __var="$1" label="$2" first second attempt
    for attempt in 1 2 3; do
        printf '%s: ' "$label" >&2
        read -rs first < /dev/tty; printf '\n' >&2
        printf 'Confirm %s: ' "$label" >&2
        read -rs second < /dev/tty; printf '\n' >&2

        if [ "$first" != "$second" ]; then
            warn "  They do not match. Try again."
            continue
        fi

        printf -v "$__var" '%s' "$first"
        return 0
    done

    die "Too many failed attempts."
}

# Whether we can actually prompt. Testing `[ -e /dev/tty ]` is not enough: the
# device node exists under cron, CI and `nohup`, but opening it fails with
# ENXIO, so a prompt there dies on an unreadable device instead of falling back
# to a generated password. The subshell open is the only reliable test.
have_tty() {
    [ -e /dev/tty ] && (exec < /dev/tty) 2>/dev/null
}

# Establishes a working privileged connection, prompting for a password when
# socket authentication is not enough. Without this, a server whose root has a
# password fails with a bare "Access denied" and no hint about what to do.
ensure_admin_connection() {
    if sql_root_quiet "SELECT 1;"; then
        return 0
    fi

    if [ -n "$ADMIN_PASSWORD" ] || [ -n "$DEFAULTS_FILE" ]; then
        die "Could not connect as '${ADMIN_USER}' with the credentials supplied."
    fi

    if ! have_tty; then
        die "Could not connect as '${ADMIN_USER}' over the socket, and there is no terminal to prompt on.
Set ADMIN_PASSWORD in the environment, or pass --defaults-file=/path/to/my.cnf."
    fi

    warn "Could not connect as '${ADMIN_USER}' over the socket without a password."
    info "This server's admin account appears to use password authentication."
    info ""

    prompt_password ADMIN_PASSWORD "MySQL password for '${ADMIN_USER}'"

    if ! sql_root_quiet "SELECT 1;"; then
        die "Still could not connect as '${ADMIN_USER}'. Check the account name with --admin-user=, or use --defaults-file=."
    fi

    green "  Connected as ${ADMIN_USER}."
    info ""
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

# The password is interpolated into two places that parse quoting: a MySQL
# string literal (IDENTIFIED BY '...') and a double-quoted .env value. Rather
# than escape correctly for both — where a mistake is a SQL injection on one
# side and a silently truncated credential on the other — refuse the five
# characters that carry meaning in either. Everything else is allowed, which
# leaves ample entropy.
validate_password_charset() {
    case "$1" in
        *\'*|*\"*|*\\*|*\`*|*\$*)
            die "The password must not contain any of:  '  \"  \\  \`  \$
Those characters carry meaning in the MySQL statement and in the .env file.
Any other punctuation is fine."
            ;;
    esac
}

# Resolves the password to set on an account, in priority order:
#   1. the named environment variable, for CI and unattended runs
#   2. an interactive prompt, when there is a terminal
#   3. a generated one
# Assigns to the variable named by $1.
resolve_new_password() {
    local __var="$1" label="$2" env_var="$3" force_generate="${4:-0}" from_env entered

    from_env="${!env_var:-}"

    if [ -n "$from_env" ]; then
        validate_password_charset "$from_env"
        printf -v "$__var" '%s' "$from_env"
        info "  Using ${env_var} from the environment."
        return 0
    fi

    if [ "$force_generate" = "1" ] || ! have_tty; then
        printf -v "$__var" '%s' "$(generate_password)"
        return 0
    fi

    info "  Press Enter to generate a strong password instead of choosing one."
    printf '%s (or Enter to generate): ' "$label" >&2
    read -rs entered < /dev/tty; printf '\n' >&2

    if [ -z "$entered" ]; then
        printf -v "$__var" '%s' "$(generate_password)"
        green "  Generated."
        return 0
    fi

    printf 'Confirm %s: ' "$label" >&2
    local confirm
    read -rs confirm < /dev/tty; printf '\n' >&2

    if [ "$entered" != "$confirm" ]; then
        die "Passwords do not match."
    fi

    validate_password_charset "$entered"

    if [ "${#entered}" -lt 12 ]; then
        warn "  That password is ${#entered} characters. This account guards every tenant's data."
        printf 'Use it anyway? [y/N]: ' >&2
        local answer
        read -r answer < /dev/tty
        case "$answer" in
            [yY]*) ;;
            *) die "Aborted. Re-run and choose a longer password." ;;
        esac
    fi

    printf -v "$__var" '%s' "$entered"
}

print_flavour() {
    info "  Server:  ${DB_VERSION}"
    info "  Flavour: ${DB_FLAVOUR}"
    info "  Socket:  ${SOCKET_PLUGIN} (${IDENTIFIED_CLAUSE})"
}
