#!/usr/bin/env bash
#
# Lets an account become the provisioner without a password.
#
# Without this rule every tenant command has to be typed with
# 'sudo -u lavoro_provisioner php artisan ...'. With the rule the commands
# elevate themselves.
#
#   sudo scripts/tenancy/setup-sudoers.sh              # for yourself
#   sudo scripts/tenancy/setup-sudoers.sh --user=guido
#   sudo scripts/tenancy/setup-sudoers.sh --deploy-user=deploy
#
# Skipping is fine: then typing sudo -u stays the way of working.

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
require_commands visudo install getent php

ADMIN_ACCOUNT=""
DEPLOY_ACCOUNT=""
PHP_PATH=""
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage: sudo scripts/tenancy/setup-sudoers.sh [options]

  --user=NAME          The account of a person running tenant commands.
                       Defaults to whoever runs this sudo.
  --deploy-user=NAME   The account of the deploy. Gets a separate, far
                       narrower rule: only mysqldump, for the backups.
  --php-path=PATH      Defaults to the path php is at now.
  --dry-run            Show what would be put in place.
  --help

Two separate files are written, and that stays so:

  /etc/sudoers.d/lavoro-admin    a person, php, so everything as the provisioner
  /etc/sudoers.d/lavoro-deploy   the deploy, only mysqldump

The second is deliberately narrow. A deploy should make backups and not be able
to create databases or users. Do not put them together, and never put php in
the deploy rule: php can start anything, so that is the same as handing the
provisioner to a process nobody is watching.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --user=*)        ADMIN_ACCOUNT="${1#*=}" ;;
        --deploy-user=*) DEPLOY_ACCOUNT="${1#*=}" ;;
        --php-path=*)    PHP_PATH="${1#*=}" ;;
        --dry-run)       DRY_RUN=1 ;;
        --help|-h)       usage; exit 0 ;;
        *)               usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 0 ]; then
    require_root
fi

# sudo compares the path literally and follows no symlinks. On Debian and
# Ubuntu /usr/bin/php is a symlink to /usr/bin/php8.3, and pcntl_exec starts
# precisely that latter path (PHP_BINARY). With only the symlink in the rule,
# sudo refuses without explanation and no command ever elevates -- while the
# rule
# goed uitziet. Daarom allebei, als ze verschillen.
if [ -z "$PHP_PATH" ]; then
    PHP_PATH="$(php -r 'echo PHP_BINARY;' 2>/dev/null || true)"
    PHP_PATH="${PHP_PATH:-$(command -v php || true)}"
fi

[ -n "$PHP_PATH" ] || die "Geen php gevonden. Geef --php-path=/pad/naar/php mee."

PHP_ALIAS="$(command -v php || true)"

if [ -n "$PHP_ALIAS" ] && [ "$PHP_ALIAS" != "$PHP_PATH" ]; then
    PHP_COMMANDS="${PHP_PATH}, ${PHP_ALIAS}"
else
    PHP_COMMANDS="${PHP_PATH}"
fi

PROV_ACCOUNT="$(grep -E '^DB_PROVISIONER_USERNAME=' "$PROJECT_ROOT/.env" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || true)"
PROV_ACCOUNT="${PROV_ACCOUNT:-$PROV_USER}"

# The accounts of real people. System accounts start below 1000 and nobody sits
# at 65534; what is in between is what someone logs in with.
login_accounts() {
    getent passwd | awk -F: '$3 >= 1000 && $3 < 65534 { print $1 }' | sort | tr '\n' ' '
}

# Why an account may not have this rule, or nothing when it may.
#
# The rule grants NOPASSWD on the php binary. php can start anything, so this is
# handing the provisioner over entirely. For a person that is defensible: they
# could already type 'sudo -u' and can read every customer password with APP_KEY
# anyway. For the web server or an unattended process it is not -- then "no
# customer can be created from a web request" is suddenly no longer true, and
# nothing in the application complains about it.
why_not() {
    local account="$1" forbidden

    for forbidden in www-data nobody nginx apache "$PROV_ACCOUNT"; do
        if [ "$account" = "$forbidden" ]; then
            printf "'%s' should not get this rule. This is for a person's account." "$account"
            return 0
        fi
    done

    if ! id "$account" >/dev/null 2>&1; then
        printf "The user '%s' does not exist on this server." "$account"
        return 0
    fi

    return 1
}

# What is passed with --user counts as it stands. Otherwise ask, with whoever
# runs this sudo as the suggestion -- run as root there is no suggestion, and
# that question is precisely the point.
if [ -z "$ADMIN_ACCOUNT" ]; then
    DEFAULT_ACCOUNT="${SUDO_USER:-}"

    if have_tty; then
        info "  Accounts on this server: $(login_accounts)"
        info ""

        for attempt in 1 2 3; do
            if [ -n "$DEFAULT_ACCOUNT" ]; then
                printf '  Which account will run the tenant commands? [%s]: ' "$DEFAULT_ACCOUNT" >&2
            else
                printf '  Which account will run the tenant commands? ' >&2
            fi

            read -r ANSWER < /dev/tty
            ADMIN_ACCOUNT="${ANSWER:-$DEFAULT_ACCOUNT}"

            if [ -z "$ADMIN_ACCOUNT" ]; then
                warn "  Give an account name."
                continue
            fi

            if COMPLAINT="$(why_not "$ADMIN_ACCOUNT")"; then
                warn "  ${COMPLAINT}"
                ADMIN_ACCOUNT=""
                continue
            fi

            break
        done

        [ -n "$ADMIN_ACCOUNT" ] || die "Geen bruikbaar account opgegeven."
        info ""
    else
        ADMIN_ACCOUNT="${DEFAULT_ACCOUNT:-${USER:-}}"
        [ -n "$ADMIN_ACCOUNT" ] || die "Could not establish which account this is about. Pass --user=NAME."
    fi
fi

if COMPLAINT="$(why_not "$ADMIN_ACCOUNT")"; then
    die "$COMPLAINT"
fi

# ---------------------------------------------------------------------------
# Writing, but only what visudo approves
# ---------------------------------------------------------------------------
#
# A sudoers file with a mistake in it makes sudo unusable altogether, and you
# only notice when you need it. So first to a temporary file, then visudo over
# it, and only then into place.

install_rule() {
    local target="$1" content="$2" temporary

    if [ "$DRY_RUN" -eq 1 ]; then
        info "==> ${target}"
        printf '%s\n' "$content"
        info ""
        return 0
    fi

    temporary="$(mktemp)"
    printf '%s\n' "$content" > "$temporary"

    if ! visudo -c -f "$temporary" >/dev/null 2>&1; then
        rm -f "$temporary"
        die "visudo rejects the rule for ${target}. Nothing has been changed."
    fi

    install -o root -g root -m 0440 "$temporary" "$target"
    rm -f "$temporary"

    green "  ${target}"
}

ADMIN_RULE="# /etc/sudoers.d/lavoro-admin
# Lets ${ADMIN_ACCOUNT} run tenant commands without typing 'sudo -u'.
# Aangemaakt door scripts/tenancy/setup-sudoers.sh
${ADMIN_ACCOUNT} ALL=(${PROV_ACCOUNT}) NOPASSWD: ${PHP_COMMANDS}"

# ---------------------------------------------------------------------------
# Does sudo read this directory at all?
# ---------------------------------------------------------------------------
#
# /etc/sudoers.d only works when /etc/sudoers pulls it in. Older sudo writes
# that as #includedir, since 1.9.1 as @includedir, and on a self-built or
# stripped installation it is sometimes not there at all. Without that line you
# write a tidy file that is never read: nothing changes
# and nothing says why.

check_sudoers_include() {
    if [ ! -r /etc/sudoers ]; then
        warn "  /etc/sudoers cannot be read; whether /etc/sudoers.d counts cannot be established."
        return 0
    fi

    if grep -qE '^[[:space:]]*[@#]includedir[[:space:]]+/etc/sudoers\.d' /etc/sudoers; then
        return 0
    fi

    die "/etc/sudoers does not pull in /etc/sudoers.d, so a file in there does nothing.
Put this line at the bottom of /etc/sudoers (with visudo):
    @includedir /etc/sudoers.d"
}

if [ "$DRY_RUN" -eq 0 ]; then
    check_sudoers_include
fi

info "==> Installing rules"
info "  account:     ${ADMIN_ACCOUNT}"
info "  becomes:     ${PROV_ACCOUNT}"
info "  through:     ${PHP_COMMANDS}"
info ""

install_rule /etc/sudoers.d/lavoro-admin "$ADMIN_RULE"

# Without --deploy-user the account running the tenant commands is also the
# account that deploys: on a server with one administrator that is the same
# person. Without this the deploy rule was silently skipped -- and the workers
# kept running the old code after every deploy.
DEPLOY_ACCOUNT="${DEPLOY_ACCOUNT:-$ADMIN_ACCOUNT}"

if [ -n "$DEPLOY_ACCOUNT" ]; then
    MYSQLDUMP_PATH="$(command -v mysqldump || command -v mariadb-dump || true)"
    [ -n "$MYSQLDUMP_PATH" ] || die "No mysqldump found; the deploy rule cannot be made."

    SYSTEMCTL_PATH="$(command -v systemctl || true)"

    DEPLOY_RULE="# /etc/sudoers.d/lavoro-deploy
# Only backups and restarting the two workers. Deliberately no php: that would
# let the deploy do everything the provisioner can, with nobody watching.
#
# Restarting belongs here because php holds on to all code at boot: without a
# restart a worker keeps running the previous version after a deploy, and
# nothing shows it except work that quietly goes wrong.
# Created by scripts/tenancy/setup-sudoers.sh
${DEPLOY_ACCOUNT} ALL=(${PROV_ACCOUNT}) NOPASSWD: ${MYSQLDUMP_PATH}"

    if [ -n "$SYSTEMCTL_PATH" ]; then
        DEPLOY_RULE="${DEPLOY_RULE}
${DEPLOY_ACCOUNT} ALL=(root) NOPASSWD: ${SYSTEMCTL_PATH} restart lavoro-worker lavoro-provisioning, ${SYSTEMCTL_PATH} restart lavoro-worker, ${SYSTEMCTL_PATH} restart lavoro-provisioning"
    fi

    install_rule /etc/sudoers.d/lavoro-deploy "$DEPLOY_RULE"
fi

if [ "$DRY_RUN" -eq 1 ]; then
    info "Nothing changed (--dry-run)."
    exit 0
fi

# ---------------------------------------------------------------------------
# Does it actually work?
# ---------------------------------------------------------------------------
#
# A rule visudo approves can still do nothing: the path to php can be slightly
# different, a later rule can override it, or sudo does not read the directory.
# You want to know that now and not when a customer has to be created. This is
# exactly what the command will try itself.

info ""
info "==> Checking that the rule works"

if sudo -u "$ADMIN_ACCOUNT" sudo -n -u "$PROV_ACCOUNT" "$PHP_PATH" -r 'exit(0);' 2>/dev/null; then
    green "  ${ADMIN_ACCOUNT} can become ${PROV_ACCOUNT} without a password."
else
    red "  ${ADMIN_ACCOUNT} still cannot become ${PROV_ACCOUNT} without a password."
    red "  Look with: sudo -u ${ADMIN_ACCOUNT} sudo -n -l"
    exit 1
fi

info ""
info "To check:"
info "  php artisan tenancy:doctor"
