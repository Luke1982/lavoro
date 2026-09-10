#!/usr/bin/env bash
# De lokale multi-tenant omgeving starten: app, beide workers en vite.
#
# .env points at a database that does not run here; .env.localtest points at the
# installation that is here, with real customers in it. This script starts that
# one and says where you get in.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

ENVIRONMENT=localtest
PORT=8199

if [ ! -f ".env.${ENVIRONMENT}" ]; then
    echo "There is no .env.${ENVIRONMENT}. Without that file there is no local installation to start."
    exit 1
fi

echo "== Checking =="

if ! APP_ENV="$ENVIRONMENT" php artisan tinker --execute='DB::connection("central")->getPdo();' >/dev/null 2>&1; then
    echo "  The central database cannot be reached. Is MySQL running?"
    echo "  Settings are in .env.${ENVIRONMENT}."
    exit 1
fi
echo "  database fine"

if lsof -i ":${PORT}" >/dev/null 2>&1; then
    echo "  Something is already listening on port ${PORT}. Stop it with: pkill -f 'artisan serve'"
    exit 1
fi
echo "  port ${PORT} is free"

if [ "${1:-}" = "--reset-logins" ]; then
    echo
    echo "== Resetting logins to 'testtest' =="
    APP_ENV="$ENVIRONMENT" php artisan tinker --execute='
        $landlord = \App\Models\Central\LandlordUser::on("central")->first();
        if ($landlord) { $landlord->forceFill(["password" => bcrypt("testtest")])->save(); }
        foreach (\App\Models\Tenant::on("central")->get() as $tenant) {
            try {
                \App\Support\Tenancy::within($tenant, function () {
                    $user = \App\Models\User::first();
                    if ($user) { $user->forceFill(["password" => bcrypt("testtest")])->save(); }
                });
            } catch (\Throwable $e) {
                // a customer with a broken database is skipped
            }
        }
    ' >/dev/null 2>&1
    echo "  done"
fi

echo
echo "== Where you get in =="
APP_ENV="$ENVIRONMENT" php artisan tinker --execute='
    $port = '"$PORT"';
    echo "  app        http://127.0.0.1:{$port}\n";
    echo "  panel      http://127.0.0.1:{$port}/beheer\n\n";
    $landlord = \App\Models\Central\LandlordUser::on("central")->first();
    echo "  admin      " . ($landlord->email ?? "-- no admin --") . "\n";
    foreach (\App\Models\Tenant::on("central")->orderBy("name")->get() as $tenant) {
        try {
            $email = \App\Support\Tenancy::within($tenant, fn () => \App\Models\User::value("email"));
            echo "  customer   {$tenant->name}: {$email}\n";
        } catch (\Throwable $e) {
            echo "  customer   {$tenant->name}: database unreachable\n";
        }
    }
    echo "\n  password: testtest (reset them with --reset-logins)\n";
' 2>/dev/null | grep -v '^$' | sed 's/^= //'

echo
echo "== Starting (ctrl-c stops everything) =="

# Vite reads .env directly and knows nothing of APP_ENV, so it gets the address
# separately; otherwise it points at the app url from .env, which does not run
# here.
#
# APP_ENV as a real environment variable and not as --env: that flag applies to
# the artisan process itself, but the requests the server handles boot again and
# then simply read .env -- with a database that does not run here.
npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" \
    "APP_ENV=${ENVIRONMENT} php artisan serve --host=127.0.0.1 --port=${PORT}" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --tries=1" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --queue=provisioning --tries=1" \
    "APP_URL=http://127.0.0.1:${PORT} npm run dev" \
    --names=app,queue,provisioning,vite
