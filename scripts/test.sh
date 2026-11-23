#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

lock_file="/tmp/lavoro-test-db.lock"

php artisan config:clear --quiet

if command -v flock > /dev/null; then
    flock "$lock_file" php artisan test "$@"
else
    php artisan test "$@"
fi

if [ "$#" -eq 0 ]; then
    npx vitest run
fi
