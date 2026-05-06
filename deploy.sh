#!/usr/bin/env bash
set -e

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
