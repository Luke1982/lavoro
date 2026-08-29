#!/usr/bin/env bash
# Importeert een bestaande enkelvoudige installatie als tenant.
set -euo pipefail

FROM=""; NAME=""; SLUG=""; PACKAGE=""; DRY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --from) FROM="$2"; shift 2 ;;
        --name) NAME="$2"; shift 2 ;;
        --slug) SLUG="$2"; shift 2 ;;
        --package) PACKAGE="$2"; shift 2 ;;
        --dry-run) DRY=1; shift ;;
        *) echo "Onbekende optie: $1" >&2; exit 2 ;;
    esac
done

[ -n "$FROM" ] && [ -n "$NAME" ] && [ -n "$SLUG" ] || { echo "--from, --name en --slug zijn verplicht" >&2; exit 2; }

DB="lavoro_tenant_${SLUG}"
run() { if [ "$DRY" -eq 1 ]; then echo "+ $*"; else "$@"; fi; }
step() { printf '\n== %s ==\n' "$1"; }

# --- 1. preflight, voordat er iets geschreven wordt ---
step "Controle vooraf"
[ -d "$FROM" ] || { echo "Bronmap bestaat niet: $FROM" >&2; exit 1; }
[ -r "$FROM/.env" ] || { echo "Kan $FROM/.env niet lezen (sudo nodig?)" >&2; exit 1; }

SRC_DB=$(grep -E '^DB_DATABASE=' "$FROM/.env" | cut -d= -f2- | tr -d '"'"'"' ')
[ -n "$SRC_DB" ] || { echo "Geen DB_DATABASE in $FROM/.env" >&2; exit 1; }

if $MYSQL -N -e "SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='$DB'" | grep -q .; then
    echo "$DB bestaat al. Verwijder hem of kies een andere slug." >&2; exit 1
fi
echo "bron: $SRC_DB -> doel: $DB"

# --- 2. dump en herstel, zonder de databasenaam uit de dump ---
step "Dump en herstel"
DUMP=$(mktemp /tmp/import-XXXXXX.sql)
trap 'rm -f "$DUMP"' EXIT
run bash -c "$MYSQLDUMP --single-transaction --routines '$SRC_DB' > '$DUMP'"
run bash -c "$MYSQL -e \"CREATE DATABASE \\\`$DB\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\""
# De dump kan zijn eigen CREATE DATABASE/USE meebrengen; die negeert het doel stil.
run bash -c "sed -e '/^CREATE DATABASE .*\`$SRC_DB\`/d' -e '/^USE \`$SRC_DB\`/d' '$DUMP' | $MYSQL '$DB'"

if [ "$DRY" -eq 0 ]; then
    TABLES=$($MYSQL -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB'")
    [ "$TABLES" -gt 0 ] || { echo "Herstel leverde 0 tabellen op." >&2; exit 1; }
    echo "tabellen: $TABLES"
fi

# --- 3. tabellen die nu centraal staan ---
step "Centrale tabellen weghalen uit de kopie"
run bash -c "$MYSQL '$DB' -e 'DROP TABLE IF EXISTS sessions, cache, cache_locks, jobs, job_batches, failed_jobs'"

# --- 4. registreren (controleert zelf op dubbele e-mailadressen) ---
step "Tenant registreren"
run php artisan tenant:setup-existing "$NAME" "$DB"

# --- 5. schema bijwerken ---
step "Schema bijwerken"
run php artisan tenants:migrate

# --- 6. bestanden ---
step "Bestanden kopieren"
TENANT_ID=$($MYSQL -N -e "SELECT id FROM lavoro_landlord.tenants WHERE JSON_UNQUOTE(JSON_EXTRACT(data,'\$.tenancy_db_name'))='$DB'")
if [ -n "${TENANT_ID:-}" ] && [ -d "$FROM/storage/app" ]; then
    run mkdir -p "storage/tenant-${TENANT_ID}/public" "storage/tenant-${TENANT_ID}/local"
    [ -d "$FROM/storage/app/public" ] && run bash -c "cp -a '$FROM/storage/app/public/.' 'storage/tenant-${TENANT_ID}/public/'"
    [ -d "$FROM/storage/app/private" ] && run bash -c "cp -a '$FROM/storage/app/private/.' 'storage/tenant-${TENANT_ID}/local/'"
    echo "tenant-id: $TENANT_ID"
fi

[ -n "$PACKAGE" ] && run php artisan tenant:package "$TENANT_ID" "$PACKAGE" || true

step "Klaar"
echo "$NAME staat als tenant $TENANT_ID op $DB"
