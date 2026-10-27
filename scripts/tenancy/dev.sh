#!/usr/bin/env bash
# The local multi-tenant installation: builds whatever is missing, then starts
# the app, both workers and vite.
#
#   scripts/tenancy/dev.sh                  build what is missing, then start
#   scripts/tenancy/dev.sh --fresh          throw the local installation away first
#   scripts/tenancy/dev.sh --reset-logins   every login back to 'testtest'
#
# Runs on the MySQL account the tests use, in databases of its own; when that
# account is missing it runs sudo scripts/tenancy/setup-test-db.sh first. The
# settings are in .env.local, which this script writes the first time; .env is
# left alone.
#
# The environment is "local" and nothing else: the service worker stays off
# there, and artisan serve gets the upload limits of .php.d.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

ENVIRONMENT=local
ENV_FILE=".env.${ENVIRONMENT}"
PORT=8199
VITE_PORT=5173
ADMIN=admin@lavoro.local
PASSWORD=testtest

FRESH=false
RESET=false

for argument in "$@"; do
    case "$argument" in
        --fresh)        FRESH=true ;;
        --reset-logins) RESET=true ;;
        *) echo "Unknown option: ${argument}"; exit 1 ;;
    esac
done

# APP_ENV as a real environment variable and not as --env: that flag applies to
# the artisan process itself, but the requests the server handles boot again and
# then simply read .env -- with a database that does not run here.
artisan() { APP_ENV="$ENVIRONMENT" php artisan "$@"; }

setting() { grep -E "^$1=" "$ENV_FILE" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
from_phpunit() { grep -oP "(?<=name=\"$1\" value=\")[^\"]+" phpunit.xml | head -n 1; }

sql() {
    MYSQL_PWD="$(setting DB_PASSWORD)" mysql --no-defaults -N -B \
        -h "$(setting DB_HOST)" -P "$(setting DB_PORT)" -u "$(setting DB_USERNAME)" "$@"
}

echo "== Checking =="

[ -d vendor ] || composer install
[ -d node_modules ] || npm ci

if [ "$FRESH" = true ] && [ -f "$ENV_FILE" ]; then
    echo "  throwing the local installation away"

    CENTRAL="$(setting DB_DATABASE)"

    # Through the provisioner, like on a server: database, login, files and the
    # addresses in the central lookup go together.
    if sql -e "SELECT 1 FROM \`${CENTRAL}\`.tenants LIMIT 1" >/dev/null 2>&1; then
        for TENANT in $(sql -e "SELECT id FROM \`${CENTRAL}\`.tenants"); do
            artisan tenant:delete "$TENANT" --force >/dev/null 2>&1 \
                || echo "  could not remove tenant ${TENANT}; its database may be left behind"
        done
    fi

    sql -e "DROP DATABASE IF EXISTS \`${CENTRAL}\`"
    rm -f "$ENV_FILE"
fi

if [ ! -f "$ENV_FILE" ]; then
    # Customer databases get a prefix of their own inside the namespace the test
    # account may grant on, so they stay apart from the ones the tests make.
    cat > "$ENV_FILE" <<ENV
APP_NAME="LOCAL Lavoro"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:${PORT}
APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')
APP_LOCALE=nl
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=$(from_phpunit DB_HOST)
DB_PORT=$(from_phpunit DB_PORT)
DB_DATABASE=lavoro_local_landlord
DB_USERNAME=$(from_phpunit DB_USERNAME)
DB_PASSWORD=$(from_phpunit DB_PASSWORD)
DB_PROVISIONER_USERNAME=$(from_phpunit DB_PROVISIONER_USERNAME)
DB_PROVISIONER_PASSWORD=$(from_phpunit DB_PROVISIONER_PASSWORD)
DB_PROVISIONER_HOST=$(from_phpunit DB_HOST)
TENANCY_DB_PREFIX=$(from_phpunit TENANCY_DB_PREFIX)local_
TENANCY_GRANT_PROCEDURE=$(from_phpunit TENANCY_GRANT_PROCEDURE)

SESSION_DRIVER=database
SESSION_CONNECTION=central
SESSION_COOKIE=lavoro_local_session
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
ENV
    echo "  wrote ${ENV_FILE}"
fi

# The MySQL account and the procedure that grants customer logins, once per
# machine. Making them takes root, so this is the one step that asks for a
# password -- and only when something is missing.
PROCEDURE="$(setting TENANCY_GRANT_PROCEDURE)"

mysql_ready() {
    [ "$(sql -e "SELECT COUNT(*) FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = '${PROCEDURE%%.*}' AND ROUTINE_NAME = '${PROCEDURE##*.}'" 2>/dev/null)" = "1" ]
}

if ! mysql_ready; then
    # Captured first: with pipefail, piping the failing client into grep makes
    # the whole test fail even when grep finds the text.
    ANSWER="$(sql -e "SELECT 1" 2>&1 || true)"

    if [[ "$ANSWER" == *"Can't connect"* ]]; then
        echo "  MySQL does not answer on $(setting DB_HOST):$(setting DB_PORT). Start it and run this again."
        exit 1
    fi

    echo "  the MySQL account for local work is not set up yet; doing that now (sudo, once per machine)"
    sudo scripts/tenancy/setup-test-db.sh

    if ! mysql_ready; then
        echo "  still not ready after that; the output above says why"
        exit 1
    fi
fi
echo "  MySQL account $(setting DB_USERNAME) ready"

sql -e "CREATE DATABASE IF NOT EXISTS \`$(setting DB_DATABASE)\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
artisan migrate --force >/dev/null
artisan tenants:migrate >/dev/null
echo "  database $(setting DB_DATABASE) up to date"

if [ "$(sql -e "SELECT COUNT(*) FROM \`$(setting DB_DATABASE)\`.landlord_users")" = "0" ]; then
    artisan landlord:user "$ADMIN" --password="$PASSWORD" >/dev/null
    echo "  admin ${ADMIN} created"
fi

if [ "$(sql -e "SELECT COUNT(*) FROM \`$(setting DB_DATABASE)\`.tenants")" = "0" ]; then
    echo "  no customers yet: installing the demo (a minute or less)"
    artisan demo:install | sed 's/^/  /'
fi

if lsof -i ":${PORT}" >/dev/null 2>&1; then
    echo "  Something is already listening on port ${PORT}. Stop it with: pkill -f 'artisan serve'"
    exit 1
fi

# Vite dies quietly when its port is taken, and the app then serves whatever
# was built last -- so a change you make does not show up and nothing says why.
if lsof -i ":${VITE_PORT}" >/dev/null 2>&1; then
    echo "  Something is already listening on port ${VITE_PORT}, where vite belongs. Stop it first,"
    echo "  or the app keeps serving the last build and your changes do not show."
    exit 1
fi
echo "  ports ${PORT} and ${VITE_PORT} are free"

if [ "$RESET" = true ]; then
    echo
    echo "== Resetting logins to '${PASSWORD}' =="
    artisan tinker --execute='
        $password = bcrypt("'"$PASSWORD"'");
        \App\Models\Central\LandlordUser::on("central")->get()->each->forceFill(["password" => $password])->each->save();
        foreach (\App\Models\Tenant::on("central")->get() as $tenant) {
            if ($tenant->isDemo()) {
                continue;
            }
            try {
                \App\Support\Tenancy::within($tenant, fn () => \App\Models\User::first()?->forceFill(["password" => $password])->save());
            } catch (\Throwable $e) {
                // a customer with a broken database is skipped
            }
        }
    ' >/dev/null 2>&1
    echo "  done (the demo keeps its own password)"
fi

echo
echo "== Where you get in =="
artisan tinker --execute='
    $port = '"$PORT"';
    echo "  app        http://127.0.0.1:{$port}\n";
    echo "  panel      http://127.0.0.1:{$port}/beheer\n\n";
    foreach (\App\Models\Central\LandlordUser::on("central")->pluck("email") as $email) {
        echo "  admin      {$email}\n";
    }
    foreach (\App\Models\Tenant::on("central")->orderBy("name")->get() as $tenant) {
        try {
            $email = $tenant->isDemo()
                ? \App\Services\Demo\DemoInstaller::LOGIN . "  (password " . \App\Services\Demo\DemoInstaller::PASSWORD . ")"
                : \App\Support\Tenancy::within($tenant, fn () => \App\Models\User::value("email"));
            echo "  customer   {$tenant->name}: {$email}\n";
        } catch (\Throwable $e) {
            echo "  customer   {$tenant->name}: database unreachable\n";
        }
    }
    echo "\n  other passwords: '"$PASSWORD"' (set them with --reset-logins)\n";
' 2>/dev/null | grep -v '^$' | sed 's/^= //'

echo
echo "== Starting (ctrl-c stops everything) =="

# Vite reads .env directly and knows nothing of APP_ENV, so it gets the address
# separately; otherwise it points at the app url from .env, which does not run
# here.
npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" \
    "APP_ENV=${ENVIRONMENT} php artisan serve --host=127.0.0.1 --port=${PORT}" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --tries=1" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --queue=provisioning --tries=1" \
    "APP_URL=http://127.0.0.1:${PORT} npm run dev" \
    --names=app,queue,provisioning,vite
