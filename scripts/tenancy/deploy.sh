#!/usr/bin/env bash
# Deploy voor de multi-tenant installatie.
set -euo pipefail
cd "$(dirname "$0")/../.."

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
php artisan queue:restart

step "Controle"
php artisan tenancy:doctor

step "Onderhoud uit"
php artisan up
trap - EXIT
echo "Klaar."
