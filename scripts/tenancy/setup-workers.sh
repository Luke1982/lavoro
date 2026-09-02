#!/usr/bin/env bash
#
# Zet de twee wachtrij-workers en de planner klaar.
#
# Zonder deze drie gebeurt er niets vanzelf: geen facturen, geen agenda-
# synchronisatie, geen werkbonnen uit onderhoudscontracten, en een nieuwe klant
# blijft in de wacht staan.
#
#   sudo scripts/tenancy/setup-workers.sh --dry-run
#   sudo scripts/tenancy/setup-workers.sh
#
# Het account en het pad worden hier afgelezen en niet aangenomen: een
# installatie in een home-map draait onder een ander account dan een in
# /var/www, en een unit met het verkeerde account erin start wel en doet niets.

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
require_commands stat php

APP_ACCOUNT=""
DRY_RUN=0
WITH_CRON=1

usage() {
    cat <<'USAGE'
Gebruik: sudo scripts/tenancy/setup-workers.sh [opties]

  --user=NAAM   Het account waaronder de site draait. Standaard de eigenaar
                van de bestanden.
  --no-cron     Alleen de workers, geen regel voor de planner.
  --dry-run     Laat zien wat er zou komen te staan.
  --help
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --user=*)  APP_ACCOUNT="${1#*=}" ;;
        --no-cron) WITH_CRON=0 ;;
        --dry-run) DRY_RUN=1 ;;
        --help|-h) usage; exit 0 ;;
        *)         usage; die "Onbekende optie: $1" ;;
    esac
    shift
done

if [ "$DRY_RUN" -eq 0 ]; then
    require_root
fi

command -v systemctl >/dev/null 2>&1 || die "Deze server draait geen systemd, dus deze units doen niets.
Zorg zelf dat deze twee blijven draaien, hoe dan ook:
  php artisan queue:work --sleep=3 --tries=3
  php artisan queue:work --queue=provisioning --tries=1 --sleep=5
en dat 'php artisan schedule:run' elke minuut draait."

# Het account waar de bestanden van zijn, is het account waaronder de site
# draait. Aannemen dat dat www-data is klopt precies zo vaak als niet.
if [ -z "$APP_ACCOUNT" ]; then
    APP_ACCOUNT="$(stat -c %U "$PROJECT_ROOT/artisan")"
fi

# Bij --dry-run alleen zeggen wat er mis is: een voorbeeld opvragen op een
# machine waar nog niets staat hoort te werken.
account_missing() {
    local account="$1" advice="$2"

    id "$account" >/dev/null 2>&1 && return 0

    if [ "$DRY_RUN" -eq 1 ]; then
        warn "  De gebruiker '${account}' bestaat hier niet. ${advice}"
        return 0
    fi

    die "De gebruiker '${account}' bestaat niet. ${advice}"
}

account_missing "$APP_ACCOUNT" "Geef --user=NAAM mee."

PROV_ACCOUNT="$(env_value DB_PROVISIONER_USERNAME)"
PROV_ACCOUNT="${PROV_ACCOUNT:-$PROV_USER}"

account_missing "$PROV_ACCOUNT" "Draai eerst: sudo scripts/tenancy/setup-mysql.sh"

PHP_PATH="$(php -r 'echo PHP_BINARY;' 2>/dev/null || command -v php)"

# Waar de databaseserver in de unit op moet wachten. Start de worker eerder,
# dan valt hij om op een verbinding die er nog niet is en probeert systemd het
# opnieuw -- werkt uiteindelijk, maar vult het logboek met ruis.
DB_UNIT="mysql.service"
for candidate in mariadb.service mysql.service mysqld.service; do
    if systemctl list-unit-files "$candidate" >/dev/null 2>&1 \
        && systemctl list-unit-files "$candidate" | grep -q "$candidate"; then
        DB_UNIT="$candidate"
        break
    fi
done

WORKER_UNIT="[Unit]
Description=Lavoro worker
After=${DB_UNIT}

[Service]
User=${APP_ACCOUNT}
WorkingDirectory=${PROJECT_ROOT}
ExecStart=${PHP_PATH} artisan queue:work --sleep=3 --tries=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"

# --tries=1 met opzet: een half aangemaakte klant opnieuw proberen loopt vast
# op 'database bestaat al' en verstopt daarmee de echte fout.
PROVISIONING_UNIT="[Unit]
Description=Lavoro provisioning worker
After=${DB_UNIT}

[Service]
User=${PROV_ACCOUNT}
Group=${PROV_ACCOUNT}
WorkingDirectory=${PROJECT_ROOT}
ExecStart=${PHP_PATH} artisan queue:work --queue=provisioning --tries=1 --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target"

CRON_LINE="* * * * * cd ${PROJECT_ROOT} && ${PHP_PATH} artisan schedule:run >> /dev/null 2>&1"

info "==> Zo komt het te staan"
info "  site draait als:     ${APP_ACCOUNT}"
info "  provisioning als:    ${PROV_ACCOUNT}"
info "  map:                 ${PROJECT_ROOT}"
info "  php:                 ${PHP_PATH}"
info "  wacht op:            ${DB_UNIT}"
info ""

if [ "$DRY_RUN" -eq 1 ]; then
    info "--- /etc/systemd/system/lavoro-worker.service"
    printf '%s\n\n' "$WORKER_UNIT"
    info "--- /etc/systemd/system/lavoro-provisioning.service"
    printf '%s\n\n' "$PROVISIONING_UNIT"
    if [ "$WITH_CRON" -eq 1 ]; then
        info "--- crontab van ${APP_ACCOUNT}"
        printf '%s\n\n' "$CRON_LINE"
    fi
    info "Niets gewijzigd (--dry-run)."
    exit 0
fi

printf '%s\n' "$WORKER_UNIT" > /etc/systemd/system/lavoro-worker.service
printf '%s\n' "$PROVISIONING_UNIT" > /etc/systemd/system/lavoro-provisioning.service
chmod 0644 /etc/systemd/system/lavoro-worker.service /etc/systemd/system/lavoro-provisioning.service

systemctl daemon-reload
systemctl enable --now lavoro-worker lavoro-provisioning >/dev/null 2>&1
systemctl restart lavoro-worker lavoro-provisioning

for unit in lavoro-worker lavoro-provisioning; do
    if systemctl is-active --quiet "$unit"; then
        green "  ${unit} draait"
    else
        red "  ${unit} draait niet. Kijk met: journalctl -u ${unit} -n 30"
    fi
done

if [ "$WITH_CRON" -eq 1 ]; then
    # De regel eerst weghalen en dan toevoegen, zodat opnieuw draaien er niet
    # elke keer een bij zet.
    EXISTING="$(crontab -u "$APP_ACCOUNT" -l 2>/dev/null | grep -v 'artisan schedule:run' || true)"
    printf '%s\n%s\n' "$EXISTING" "$CRON_LINE" | sed '/^$/d' | crontab -u "$APP_ACCOUNT" -
    green "  planner staat in de crontab van ${APP_ACCOUNT}"
fi

info ""
info "De workers melden zich binnen een minuut. Daarna:"
info "  php artisan tenancy:doctor"
