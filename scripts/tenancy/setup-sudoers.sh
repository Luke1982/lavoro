#!/usr/bin/env bash
#
# Laat een account zonder wachtwoord de provisioner worden.
#
# Zonder deze regel moet elk tenant-commando met 'sudo -u lavoro_provisioner
# php artisan ...' getypt worden. Met de regel verheffen de commando's zichzelf.
#
#   sudo scripts/tenancy/setup-sudoers.sh              # voor jezelf
#   sudo scripts/tenancy/setup-sudoers.sh --user=guido
#   sudo scripts/tenancy/setup-sudoers.sh --deploy-user=deploy
#
# Overslaan mag: dan blijft het typen van sudo -u de manier van werken.

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
require_commands visudo install getent php

ADMIN_ACCOUNT=""
DEPLOY_ACCOUNT=""
PHP_PATH=""
DRY_RUN=0

usage() {
    cat <<'USAGE'
Gebruik: sudo scripts/tenancy/setup-sudoers.sh [opties]

  --user=NAAM          Het account van een mens dat tenant-commando's draait.
                       Standaard degene die deze sudo draait.
  --deploy-user=NAAM   Het account van de deploy. Krijgt een aparte, veel
                       smallere regel: alleen mysqldump, voor de back-ups.
  --php-path=PAD       Standaard het pad waar php nu staat.
  --dry-run            Laat zien wat er zou komen te staan.
  --help

Er worden twee losse bestanden geschreven, en dat blijft zo:

  /etc/sudoers.d/lavoro-admin    een mens, php, dus alles als de provisioner
  /etc/sudoers.d/lavoro-deploy   de deploy, alleen mysqldump

Die tweede is met opzet smal. Een deploy hoort back-ups te maken en geen
databases of gebruikers aan te kunnen maken. Zet ze niet bij elkaar en zet in
de deploy-regel nooit php: php kan alles starten, dus dat is hetzelfde als de
provisioner weggeven aan een proces waar niemand naar kijkt.
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

# sudo vergelijkt het pad letterlijk en volgt geen symlinks. /usr/bin/php is op
# Debian en Ubuntu een symlink naar /usr/bin/php8.3, en pcntl_exec start juist
# dat laatste pad (PHP_BINARY). Staat alleen de symlink in de regel, dan weigert
# sudo zonder uitleg en verheft er nooit een commando -- terwijl de regel er
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

# De accounts van echte mensen. Systeemaccounts beginnen onder 1000 en nobody
# zit op 65534; wat daartussen zit is waar iemand mee inlogt.
login_accounts() {
    getent passwd | awk -F: '$3 >= 1000 && $3 < 65534 { print $1 }' | sort | tr '\n' ' '
}

# Waarom een account deze regel niet mag hebben, of niets als het wel mag.
#
# De regel geeft NOPASSWD op de php-binary. php kan van alles starten, dus dit
# is de provisioner helemaal weggeven. Voor een mens is dat te verdedigen: die
# kon al 'sudo -u' typen en kan met APP_KEY toch al elk klantwachtwoord lezen.
# Voor de webserver of een onbewaakt proces is het dat niet -- dan is "vanuit
# een webverzoek kan er geen klant aangemaakt worden" ineens niet meer waar, en
# er is niets in de applicatie dat daarover klaagt.
why_not() {
    local account="$1" forbidden

    for forbidden in www-data nobody nginx apache "$PROV_ACCOUNT"; do
        if [ "$account" = "$forbidden" ]; then
            printf "'%s' hoort deze regel niet te krijgen. Dit is voor het account van een mens." "$account"
            return 0
        fi
    done

    if ! id "$account" >/dev/null 2>&1; then
        printf "De gebruiker '%s' bestaat niet op deze server." "$account"
        return 0
    fi

    return 1
}

# Meegegeven met --user telt zoals het er staat. Anders vragen, met degene die
# deze sudo draait als voorzet -- draai je als root, dan is er geen voorzet en
# is die vraag juist het punt.
if [ -z "$ADMIN_ACCOUNT" ]; then
    DEFAULT_ACCOUNT="${SUDO_USER:-}"

    if have_tty; then
        info "  Accounts op deze server: $(login_accounts)"
        info ""

        for attempt in 1 2 3; do
            if [ -n "$DEFAULT_ACCOUNT" ]; then
                printf '  Welk account draait straks de tenant-opdrachten? [%s]: ' "$DEFAULT_ACCOUNT" >&2
            else
                printf '  Welk account draait straks de tenant-opdrachten? ' >&2
            fi

            read -r ANSWER < /dev/tty
            ADMIN_ACCOUNT="${ANSWER:-$DEFAULT_ACCOUNT}"

            if [ -z "$ADMIN_ACCOUNT" ]; then
                warn "  Geef een accountnaam op."
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
        [ -n "$ADMIN_ACCOUNT" ] || die "Kon niet vaststellen om welk account het gaat. Geef --user=NAAM mee."
    fi
fi

if COMPLAINT="$(why_not "$ADMIN_ACCOUNT")"; then
    die "$COMPLAINT"
fi

# ---------------------------------------------------------------------------
# Wie deze regel niet mag hebben
# ---------------------------------------------------------------------------
#
# De regel geeft NOPASSWD op de php-binary. php kan van alles starten, dus dit
# is de provisioner helemaal weggeven. Voor een mens is dat te verdedigen: die
# kon al 'sudo -u' typen en kan met APP_KEY toch al elk klantwachtwoord lezen.
# Voor de webserver of een onbewaakt proces is het dat niet -- dan is "vanuit
# een webverzoek kan er geen klant aangemaakt worden" ineens niet meer waar, en
# er is niets in de applicatie dat daarover klaagt.

# ---------------------------------------------------------------------------
# Schrijven, maar alleen wat visudo goedkeurt
# ---------------------------------------------------------------------------
#
# Een sudoers-bestand met een fout erin maakt sudo in zijn geheel onbruikbaar,
# en dat merk je pas als je hem nodig hebt. Daarom eerst naar een tijdelijk
# bestand, dan visudo erover, en pas daarna op zijn plek.

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
        die "visudo keurt de regel voor ${target} af. Er is niets gewijzigd."
    fi

    install -o root -g root -m 0440 "$temporary" "$target"
    rm -f "$temporary"

    green "  ${target}"
}

ADMIN_RULE="# /etc/sudoers.d/lavoro-admin
# Laat ${ADMIN_ACCOUNT} tenant-commando's draaien zonder 'sudo -u' te typen.
# Aangemaakt door scripts/tenancy/setup-sudoers.sh
${ADMIN_ACCOUNT} ALL=(${PROV_ACCOUNT}) NOPASSWD: ${PHP_COMMANDS}"

# ---------------------------------------------------------------------------
# Leest sudo deze map uberhaupt?
# ---------------------------------------------------------------------------
#
# /etc/sudoers.d werkt alleen als /etc/sudoers hem binnenhaalt. Oudere sudo
# schrijft dat als #includedir, sinds 1.9.1 als @includedir, en op een
# zelfgebouwde of uitgeklede installatie staat het er soms niet. Zonder die
# regel schrijf je een keurig bestand dat nooit gelezen wordt: er verandert
# niets en niets zegt waarom.

check_sudoers_include() {
    if [ ! -r /etc/sudoers ]; then
        warn "  /etc/sudoers is niet te lezen; of /etc/sudoers.d meetelt is niet na te gaan."
        return 0
    fi

    if grep -qE '^[[:space:]]*[@#]includedir[[:space:]]+/etc/sudoers\.d' /etc/sudoers; then
        return 0
    fi

    die "/etc/sudoers haalt /etc/sudoers.d niet binnen, dus een bestand daarin doet niets.
Zet deze regel onderaan /etc/sudoers (met visudo):
    @includedir /etc/sudoers.d"
}

if [ "$DRY_RUN" -eq 0 ]; then
    check_sudoers_include
fi

info "==> Regels installeren"
info "  account:     ${ADMIN_ACCOUNT}"
info "  wordt:       ${PROV_ACCOUNT}"
info "  via:         ${PHP_COMMANDS}"
info ""

install_rule /etc/sudoers.d/lavoro-admin "$ADMIN_RULE"

if [ -n "$DEPLOY_ACCOUNT" ]; then
    MYSQLDUMP_PATH="$(command -v mysqldump || command -v mariadb-dump || true)"
    [ -n "$MYSQLDUMP_PATH" ] || die "Geen mysqldump gevonden; de deploy-regel kan niet gemaakt worden."

    DEPLOY_RULE="# /etc/sudoers.d/lavoro-deploy
# Alleen back-ups. Met opzet geen php: dat zou de deploy alles laten doen wat
# de provisioner kan, zonder dat iemand meekijkt.
# Aangemaakt door scripts/tenancy/setup-sudoers.sh
${DEPLOY_ACCOUNT} ALL=(${PROV_ACCOUNT}) NOPASSWD: ${MYSQLDUMP_PATH}"

    install_rule /etc/sudoers.d/lavoro-deploy "$DEPLOY_RULE"
fi

if [ "$DRY_RUN" -eq 1 ]; then
    info "Niets gewijzigd (--dry-run)."
    exit 0
fi

# ---------------------------------------------------------------------------
# Werkt het ook echt?
# ---------------------------------------------------------------------------
#
# Een regel die visudo goedkeurt kan nog steeds niets doen: het pad naar php
# kan net anders zijn, een latere regel kan hem overrulen, of sudo leest de map
# niet. Dat wil je nu weten en niet als er een klant aangemaakt moet worden.
# Dit is precies wat het commando straks zelf probeert.

info ""
info "==> Nakijken of de regel werkt"

if sudo -u "$ADMIN_ACCOUNT" sudo -n -u "$PROV_ACCOUNT" "$PHP_PATH" -r 'exit(0);' 2>/dev/null; then
    green "  ${ADMIN_ACCOUNT} kan zonder wachtwoord ${PROV_ACCOUNT} worden."
else
    red "  Het lukt ${ADMIN_ACCOUNT} nog steeds niet om zonder wachtwoord ${PROV_ACCOUNT} te worden."
    red "  Kijk met: sudo -u ${ADMIN_ACCOUNT} sudo -n -l"
    exit 1
fi

info ""
info "Controleren:"
info "  php artisan tenancy:doctor"
