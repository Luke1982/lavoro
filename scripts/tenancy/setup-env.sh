#!/usr/bin/env bash
#
# Puts .env in place for a multi-tenant installation.
#
# The counterpart of setup-mysql.sh: that script creates the database accounts
# and sets the DB_ keys, this script sets the rest. Together they cover
# everything that
# tenancy:doctor over .env te zeggen heeft.
#
#   scripts/tenancy/setup-env.sh
#   scripts/tenancy/setup-env.sh --url=https://lavoro.example --mail-host=smtp.example
#
# Runs without root and does not touch the database. Safe to run
# draaien: bestaande waarden blijven staan tenzij je ze overschrijft.

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
require_commands base64 php

APP_URL_OPT=""
APP_KEY_OPT=""
MAIL_HOST_OPT=""
MAIL_PORT_OPT=""
MAIL_USERNAME_OPT=""
MAIL_FROM_OPT=""
MAIL_FROM_NAME_OPT=""
ASSUME_YES=0
DRY_RUN=0

usage() {
    cat <<'USAGE'
Usage: scripts/tenancy/setup-env.sh [options]

Without options every value is asked for, with what is in .env now as the
suggestion. Enter keeps that value.

  --url=URL                  The address Lavoro runs on
  --app-key=base64:...       The APP_KEY of the existing installation
  --mail-host=HOST           SMTP server for the invoices you send
  --mail-port=PORT           Defaults to 587
  --mail-username=NAME
  --mail-from=ADDRESS
  --mail-from-name=NAME
  --yes                      Ask nothing; only set what was passed in
  --dry-run                  Show what would be set, and write nothing
  --help

The mail server password comes from LANDLORD_MAIL_PASSWORD in the environment,
or is asked for. It never goes on the command line, because there it is visible
to everyone on the server.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --url=*)            APP_URL_OPT="${1#*=}" ;;
        --app-key=*)        APP_KEY_OPT="${1#*=}" ;;
        --mail-host=*)      MAIL_HOST_OPT="${1#*=}" ;;
        --mail-port=*)      MAIL_PORT_OPT="${1#*=}" ;;
        --mail-username=*)  MAIL_USERNAME_OPT="${1#*=}" ;;
        --mail-from=*)      MAIL_FROM_OPT="${1#*=}" ;;
        --mail-from-name=*) MAIL_FROM_NAME_OPT="${1#*=}" ;;
        --yes|-y)           ASSUME_YES=1 ;;
        --dry-run)          DRY_RUN=1; ASSUME_YES=1 ;;
        --help|-h)          usage; exit 0 ;;
        *)                  usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

ENV_FILE="$PROJECT_ROOT/.env"

if [ ! -f "$ENV_FILE" ]; then
    [ -f "$PROJECT_ROOT/.env.example" ] || die "No .env and no .env.example in $PROJECT_ROOT."

    if [ "$DRY_RUN" -eq 1 ]; then
        warn "There is no .env yet; it would be made from .env.example."
        ENV_FILE="$PROJECT_ROOT/.env.example"
    else
        cp "$PROJECT_ROOT/.env.example" "$ENV_FILE"
        green "Nieuwe .env gemaakt uit .env.example"
    fi
fi

# With --dry-run only show what would be put in place. That way you see what
# changes before anything changes, like in the other scripts.
set_key() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '  %s=%s\n' "$1" "$2"
    else
        env_set "$ENV_FILE" "$1" "$2"
    fi
}

interactive() {
    [ "$ASSUME_YES" -eq 0 ] && have_tty
}

# Asks one value, with what is there now as the suggestion. Enter keeps it.
# Order: what was passed in wins, then the answer, then what was already there.
ask() {
    local __var="$1" label="$2" given="$3" current="$4" answer

    if [ -n "$given" ]; then
        printf -v "$__var" '%s' "$given"
        return 0
    fi

    if ! interactive; then
        printf -v "$__var" '%s' "$current"
        return 0
    fi

    if [ -n "$current" ]; then
        printf '%s [%s]: ' "$label" "$current" >&2
    else
        printf '%s: ' "$label" >&2
    fi

    read -r answer < /dev/tty
    printf -v "$__var" '%s' "${answer:-$current}"
}

info "==> .env bijwerken"

if [ "$DRY_RUN" -eq 0 ]; then
    env_backup "$ENV_FILE"
fi

# ---------------------------------------------------------------------------
# What is not up for discussion
# ---------------------------------------------------------------------------
#
# These values are not a preference but a precondition. The queue on sync makes
# provisioning run inside the web request, as the account that may precisely not
# create databases. A session that is not central looks in the wrong database
# when logging in. A cache that is not in the database does not share the
# separation between customers. And MAIL_MAILER has to be on tenant, otherwise
# every customer sends post from another company's mailbox.

info "  Vaste waarden"

set_key APP_ENV production
set_key APP_DEBUG false
set_key SESSION_DRIVER database
set_key SESSION_CONNECTION central
set_key QUEUE_CONNECTION database
set_key CACHE_STORE database
set_key MAIL_MAILER tenant

# The application is Dutch. Without this Laravel's own messages -- "The collect
# on field is required" -- appear in English on screen, in the middle of a Dutch
# form. The translations are in lang/nl.
set_key APP_LOCALE nl
set_key APP_FALLBACK_LOCALE en

# ---------------------------------------------------------------------------
# APP_KEY
# ---------------------------------------------------------------------------
#
# This key unlocks every stored Google integration, every customer database
# password and every encrypted field. A new key on an existing installation
# makes that data unreadable, and there is no way back. So an existing key is
# never replaced.

CURRENT_KEY="$(env_value APP_KEY "$ENV_FILE")"

key_is_valid() {
    local key="${1#base64:}"
    [ "$1" != "$key" ] || return 1
    [ "$(printf '%s' "$key" | base64 -d 2>/dev/null | wc -c)" -eq 32 ]
}

if [ -n "$APP_KEY_OPT" ]; then
    key_is_valid "$APP_KEY_OPT" || die "That APP_KEY is not right. Expected 'base64:' followed by 32 bytes.
Copy it literally from the .env of the old installation."
    set_key APP_KEY "$APP_KEY_OPT"
    green "  APP_KEY overgenomen."
elif [ -n "$CURRENT_KEY" ]; then
    info "  APP_KEY was already there; not touched."
elif interactive; then
    info ""
    warn "  There is no APP_KEY in .env yet."
    info "  If you are moving an existing installation, copy the APP_KEY from the old .env."
    info "  Without that key everything stored encrypted is unreadable."
    info ""
    printf '  APP_KEY of the old installation (or Enter for a new one): ' >&2
    read -r PASTED_KEY < /dev/tty

    if [ -n "$PASTED_KEY" ]; then
        key_is_valid "$PASTED_KEY" || die "That key is not right. Expected 'base64:' followed by 32 bytes."
        set_key APP_KEY "$PASTED_KEY"
        green "  APP_KEY copied."
    elif [ "$DRY_RUN" -eq 1 ]; then
        info "  A new APP_KEY would be made."
    else
        (cd "$PROJECT_ROOT" && php artisan key:generate --force)
        green "  New APP_KEY made. Keep it: without that key nothing can be read any more."
    fi
else
    warn "  No APP_KEY and nothing to ask on. Set it yourself, or run 'php artisan key:generate'."
fi

# ---------------------------------------------------------------------------
# Address and post
# ---------------------------------------------------------------------------

info ""
info "  Address"

ask APP_URL "  Address Lavoro runs on" "$APP_URL_OPT" "$(env_value APP_URL "$ENV_FILE")"
if [ -n "$APP_URL" ]; then set_key APP_URL "$APP_URL"; fi

info ""
info "  Mail server for the invoices you send to customers"
info "  (customers send their own post with their own settings)"

ask MAIL_HOST      "  SMTP server"  "$MAIL_HOST_OPT"      "$(env_value LANDLORD_MAIL_HOST "$ENV_FILE")"
ask MAIL_PORT      "  Port"          "$MAIL_PORT_OPT"      "$(env_value LANDLORD_MAIL_PORT "$ENV_FILE")"
ask MAIL_USERNAME  "  User name"     "$MAIL_USERNAME_OPT"  "$(env_value LANDLORD_MAIL_USERNAME "$ENV_FILE")"
ask MAIL_FROM      "  Sender address" "$MAIL_FROM_OPT"     "$(env_value LANDLORD_MAIL_FROM_ADDRESS "$ENV_FILE")"
ask MAIL_FROM_NAME "  Sender name"   "$MAIL_FROM_NAME_OPT" "$(env_value LANDLORD_MAIL_FROM_NAME "$ENV_FILE")"

if [ -n "$MAIL_HOST" ];      then set_key LANDLORD_MAIL_HOST "$MAIL_HOST"; fi
if [ -n "$MAIL_USERNAME" ];  then set_key LANDLORD_MAIL_USERNAME "$MAIL_USERNAME"; fi
if [ -n "$MAIL_FROM" ];      then set_key LANDLORD_MAIL_FROM_ADDRESS "$MAIL_FROM"; fi
if [ -n "$MAIL_FROM_NAME" ]; then set_key LANDLORD_MAIL_FROM_NAME "$MAIL_FROM_NAME"; fi

set_key LANDLORD_MAIL_PORT "${MAIL_PORT:-587}"

# The password comes from the environment or from a prompt, never from the
# command line: there it sits in `ps` and in the shell's history.
MAIL_PASSWORD="${LANDLORD_MAIL_PASSWORD:-}"

if [ -n "$MAIL_PASSWORD" ]; then
    set_key LANDLORD_MAIL_PASSWORD "$MAIL_PASSWORD"
    info "  Password taken from LANDLORD_MAIL_PASSWORD."
elif [ -z "$(env_value LANDLORD_MAIL_PASSWORD "$ENV_FILE")" ] && [ -n "$MAIL_HOST" ] && interactive; then
    prompt_password MAIL_PASSWORD "  Mail server password"
    set_key LANDLORD_MAIL_PASSWORD "$MAIL_PASSWORD"
fi

info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "Nothing changed (--dry-run)."
    exit 0
fi

green ".env updated."
info ""
info "After this:"
info "  php artisan migrate --force"
info "  php artisan tenancy:doctor"
