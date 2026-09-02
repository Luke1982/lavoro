#!/usr/bin/env bash
#
# Zet .env klaar voor een multi-tenant installatie.
#
# Het tegenhangertje van setup-mysql.sh: dat script maakt de databaseaccounts
# en zet de DB_-sleutels, dit script zet de rest. Samen dekken ze alles wat
# tenancy:doctor over .env te zeggen heeft.
#
#   scripts/tenancy/setup-env.sh
#   scripts/tenancy/setup-env.sh --url=https://lavoro.example --mail-host=smtp.example
#
# Draait zonder root en raakt de database niet aan. Veilig om opnieuw te
# draaien: bestaande waarden blijven staan tenzij je ze overschrijft.

set -euo pipefail

# Zonder dirname: dit staat boven het inlezen van lib.sh, dus een fout hier
# komt eruit als een klacht over een bestand dat niet gevonden wordt. De shell
# kan dit zelf, en dan hoeft er niets te bestaan om hier te komen.
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
Gebruik: scripts/tenancy/setup-env.sh [opties]

Zonder opties wordt er per waarde gevraagd, met wat er nu in .env staat als
voorzet. Enter houdt die waarde.

  --url=URL                  Het adres waarop Lavoro draait
  --app-key=base64:...       De APP_KEY van de bestaande installatie
  --mail-host=HOST           SMTP-server voor de facturen die jij verstuurt
  --mail-port=PORT           Standaard 587
  --mail-username=NAAM
  --mail-from=ADRES
  --mail-from-name=NAAM
  --yes                      Niets vragen; alleen zetten wat is meegegeven
  --dry-run                  Laat zien wat er gezet zou worden, en schrijf niets
  --help

Het wachtwoord van de mailserver komt uit LANDLORD_MAIL_PASSWORD in de
omgeving, of wordt gevraagd. Het komt nooit op de opdrachtregel te staan,
want daar is het voor iedereen op de server zichtbaar.
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
    [ -f "$PROJECT_ROOT/.env.example" ] || die "Geen .env en geen .env.example in $PROJECT_ROOT."

    if [ "$DRY_RUN" -eq 1 ]; then
        warn "Er is nog geen .env; die zou uit .env.example gemaakt worden."
        ENV_FILE="$PROJECT_ROOT/.env.example"
    else
        cp "$PROJECT_ROOT/.env.example" "$ENV_FILE"
        green "Nieuwe .env gemaakt uit .env.example"
    fi
fi

# Bij --dry-run alleen laten zien wat er zou komen te staan. Zo is te zien wat
# er verandert voordat er iets verandert, net als bij de andere scripts.
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

# Vraagt één waarde, met wat er nu staat als voorzet. Enter houdt die.
# Volgorde: wat is meegegeven wint, dan het antwoord, dan wat er al stond.
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
# Wat niet ter discussie staat
# ---------------------------------------------------------------------------
#
# Deze waarden zijn geen voorkeur maar een voorwaarde. De wachtrij op sync laat
# provisioning in het webverzoek draaien, als het account dat juist geen
# databases mag maken. Een sessie die niet centraal staat zoekt bij het inloggen
# in de verkeerde database. Een cache die niet in de database staat deelt de
# scheiding tussen klanten niet. En MAIL_MAILER moet op tenant, anders verstuurt
# iedere klant post vanuit de mailbox van een ander bedrijf.

info "  Vaste waarden"

set_key APP_ENV production
set_key APP_DEBUG false
set_key SESSION_DRIVER database
set_key SESSION_CONNECTION central
set_key QUEUE_CONNECTION database
set_key CACHE_STORE database
set_key MAIL_MAILER tenant

# ---------------------------------------------------------------------------
# APP_KEY
# ---------------------------------------------------------------------------
#
# Deze sleutel ontsluit elke opgeslagen Google-koppeling, elk klantwachtwoord
# voor de database en elk versleuteld veld. Een nieuwe sleutel op een bestaande
# installatie maakt die gegevens onleesbaar, en er is geen weg terug. Daarom
# wordt een bestaande sleutel nooit vervangen.

CURRENT_KEY="$(env_value APP_KEY "$ENV_FILE")"

key_is_valid() {
    local key="${1#base64:}"
    [ "$1" != "$key" ] || return 1
    [ "$(printf '%s' "$key" | base64 -d 2>/dev/null | wc -c)" -eq 32 ]
}

if [ -n "$APP_KEY_OPT" ]; then
    key_is_valid "$APP_KEY_OPT" || die "Die APP_KEY klopt niet. Verwacht 'base64:' met daarachter 32 bytes.
Neem hem letterlijk over uit de .env van de oude installatie."
    set_key APP_KEY "$APP_KEY_OPT"
    green "  APP_KEY overgenomen."
elif [ -n "$CURRENT_KEY" ]; then
    info "  APP_KEY stond er al; niet aangeraakt."
elif interactive; then
    info ""
    warn "  Er staat nog geen APP_KEY in .env."
    info "  Verhuis je een bestaande installatie, neem dan de APP_KEY over uit de oude .env."
    info "  Zonder die sleutel is alles wat versleuteld is opgeslagen onleesbaar."
    info ""
    printf '  APP_KEY van de oude installatie (of Enter voor een nieuwe): ' >&2
    read -r PASTED_KEY < /dev/tty

    if [ -n "$PASTED_KEY" ]; then
        key_is_valid "$PASTED_KEY" || die "Die sleutel klopt niet. Verwacht 'base64:' met daarachter 32 bytes."
        set_key APP_KEY "$PASTED_KEY"
        green "  APP_KEY overgenomen."
    elif [ "$DRY_RUN" -eq 1 ]; then
        info "  Er zou een nieuwe APP_KEY gemaakt worden."
    else
        (cd "$PROJECT_ROOT" && php artisan key:generate --force)
        green "  Nieuwe APP_KEY gemaakt. Bewaar hem: zonder die sleutel is niets meer te lezen."
    fi
else
    warn "  Geen APP_KEY en niets om te vragen. Zet hem zelf, of draai 'php artisan key:generate'."
fi

# ---------------------------------------------------------------------------
# Adres en post
# ---------------------------------------------------------------------------

info ""
info "  Adres"

ask APP_URL "  Adres waarop Lavoro draait" "$APP_URL_OPT" "$(env_value APP_URL "$ENV_FILE")"
if [ -n "$APP_URL" ]; then set_key APP_URL "$APP_URL"; fi

info ""
info "  Mailserver voor de facturen die jij naar klanten stuurt"
info "  (klanten versturen hun eigen post met hun eigen instellingen)"

ask MAIL_HOST      "  SMTP-server"   "$MAIL_HOST_OPT"      "$(env_value LANDLORD_MAIL_HOST "$ENV_FILE")"
ask MAIL_PORT      "  Poort"         "$MAIL_PORT_OPT"      "$(env_value LANDLORD_MAIL_PORT "$ENV_FILE")"
ask MAIL_USERNAME  "  Gebruikersnaam" "$MAIL_USERNAME_OPT" "$(env_value LANDLORD_MAIL_USERNAME "$ENV_FILE")"
ask MAIL_FROM      "  Afzenderadres" "$MAIL_FROM_OPT"      "$(env_value LANDLORD_MAIL_FROM_ADDRESS "$ENV_FILE")"
ask MAIL_FROM_NAME "  Afzendernaam"  "$MAIL_FROM_NAME_OPT" "$(env_value LANDLORD_MAIL_FROM_NAME "$ENV_FILE")"

if [ -n "$MAIL_HOST" ];      then set_key LANDLORD_MAIL_HOST "$MAIL_HOST"; fi
if [ -n "$MAIL_USERNAME" ];  then set_key LANDLORD_MAIL_USERNAME "$MAIL_USERNAME"; fi
if [ -n "$MAIL_FROM" ];      then set_key LANDLORD_MAIL_FROM_ADDRESS "$MAIL_FROM"; fi
if [ -n "$MAIL_FROM_NAME" ]; then set_key LANDLORD_MAIL_FROM_NAME "$MAIL_FROM_NAME"; fi

set_key LANDLORD_MAIL_PORT "${MAIL_PORT:-587}"

# Het wachtwoord komt uit de omgeving of uit een prompt, nooit van de
# opdrachtregel: daar staat het in `ps` en in de geschiedenis van de shell.
MAIL_PASSWORD="${LANDLORD_MAIL_PASSWORD:-}"

if [ -n "$MAIL_PASSWORD" ]; then
    set_key LANDLORD_MAIL_PASSWORD "$MAIL_PASSWORD"
    info "  Wachtwoord uit LANDLORD_MAIL_PASSWORD overgenomen."
elif [ -z "$(env_value LANDLORD_MAIL_PASSWORD "$ENV_FILE")" ] && [ -n "$MAIL_HOST" ] && interactive; then
    prompt_password MAIL_PASSWORD "  Wachtwoord van de mailserver"
    set_key LANDLORD_MAIL_PASSWORD "$MAIL_PASSWORD"
fi

info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "Niets gewijzigd (--dry-run)."
    exit 0
fi

green ".env bijgewerkt."
info ""
info "Hierna:"
info "  php artisan migrate --force"
info "  php artisan tenancy:doctor"
