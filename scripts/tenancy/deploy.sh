#!/usr/bin/env bash
# Deploy voor de multi-tenant installatie.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

step() { printf '\n== %s ==\n' "$1"; }

step "Onderhoud aan"
php artisan app:maintenance --message="We zijn zo terug." || php artisan down

restore() { php artisan up || true; }
trap restore EXIT

step "Back-up van elke database"
STAMP=$(date +%Y-%m-%d_%H-%M-%S)
mkdir -p storage/backups
LANDLORD=$(php artisan tinker --execute='echo config("database.connections.central.database");' 2>/dev/null | tail -1)
mysqldump --single-transaction "$LANDLORD" | gzip > "storage/backups/${LANDLORD}-${STAMP}.sql.gz"

# De oude deploy maakte alleen een back-up van DB_DATABASE. Dat is nu de kleine
# centrale registratie, en zonder deze lus zou geen enkele klant meer geback-upt
# worden -- zonder foutmelding.
php artisan tenants:list --check >/dev/null
for DB in $(mysql -N -e "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME LIKE 'lavoro_tenant_%'"); do
    mysqldump --single-transaction "$DB" | gzip > "storage/backups/${DB}-${STAMP}.sql.gz"
    echo "  $DB"
done

step "Code"
# De build maakt public/service-worker.js opnieuw. Zolang die op deze server nog
# in git zit, blokkeert zijn eigen wijziging de pull die hem er juist uithaalt.
for GENERATED in public/service-worker.js; do
    if git ls-files --error-unmatch "$GENERATED" >/dev/null 2>&1; then
        git checkout -- "$GENERATED" 2>/dev/null || true
    fi
done

# Een pull met --ff-only weigert zodra een gevolgd bestand lokaal gewijzigd is.
# Dat is terecht, maar de kale git-melding zegt niet welk bestand het is.
DIRTY=$(git status --porcelain --untracked-files=no)
if [ -n "$DIRTY" ]; then
    echo "$DIRTY"
    echo
    echo "  Lokale wijzigingen in gevolgde bestanden: de pull zou ze overschrijven."
    echo "  Bekijk ze met 'git diff' en zet ze terug of leg ze vast, en draai daarna"
    echo "  deze deploy opnieuw. Er is nog niets uitgerold; de site staat weer aan."
    exit 1
fi

git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build

step "Migraties"
php artisan migrate --force          # centraal
php artisan tenants:migrate          # elke tenant -- zonder dit blijft ieder schema achter

step "Caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Beide workers halen hun code opnieuw op: de gewone en die van de provisioning.
php artisan queue:restart

# Ook php onder de webserver houdt de gecompileerde code vast. Zonder dit draait
# hij door op de oude klassen terwijl de sjablonen al nieuw zijn.
# LiteSpeed heeft geen unit voor lsphp: de processen worden vanzelf opnieuw
# gestart zodra ze weg zijn, dus pkill is daar de manier.
systemctl reload php8.3-fpm 2>/dev/null \
    || pkill lsphp 2>/dev/null \
    || echo "  Let op: php onder de webserver zelf herstarten (opcache)."

step "Controle"
# Twee controles, elk met een eigen bereik: het script kijkt naar de rechten van
# de databaseaccounts (root nodig), de doctor naar de rest van de opstelling.
scripts/tenancy/verify-mysql.sh
php artisan tenancy:doctor

step "Onderhoud uit"
php artisan up
trap - EXIT
echo "Klaar."
