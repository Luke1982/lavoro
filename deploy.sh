#!/usr/bin/env bash
set -e

BACKUP_DIR="$(dirname "$0")/storage/backups/db"
mkdir -p "$BACKUP_DIR"

echo "==> Creating database backup..."
DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d '=' -f2-)
DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d '=' -f2-)
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | cut -d '=' -f2-)
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d '=' -f2-)
DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d '=' -f2-)
BACKUP_FILE="$BACKUP_DIR/$(date +%Y-%m-%d_%H-%M-%S).sql.gz"
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    -h "${DB_HOST:-127.0.0.1}" \
    -P "${DB_PORT:-3306}" \
    -u "$DB_USERNAME" \
    "$DB_DATABASE" | gzip > "$BACKUP_FILE"
echo "    Backup saved to $BACKUP_FILE"

echo "==> Pruning old backups (keeping 5)..."
ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm --
echo "    Done pruning."

echo "==> Pulling latest from master..."
git fetch origin master
git reset --hard origin/master

echo "==> Running database migrations..."
php artisan migrate --force

echo "==> Updating Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

echo "==> Updating NPM dependencies..."
npm ci

echo "==> Building frontend assets..."
npm run build

echo "==> Clearing caches..."
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo "==> Done."
