#!/usr/bin/env bash
# De lokale multi-tenant omgeving starten: app, beide workers en vite.
#
# .env wijst naar een database die hier niet draait; .env.localtest wijst naar
# de installatie die er wel staat, met echte klanten erin. Dit script start die
# en zegt waar je binnenkomt.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

ENVIRONMENT=localtest
PORT=8199

if [ ! -f ".env.${ENVIRONMENT}" ]; then
    echo "Er is geen .env.${ENVIRONMENT}. Zonder dat bestand is er geen lokale installatie om te starten."
    exit 1
fi

echo "== Nakijken =="

if ! APP_ENV="$ENVIRONMENT" php artisan tinker --execute='DB::connection("central")->getPdo();' >/dev/null 2>&1; then
    echo "  De centrale database is niet bereikbaar. Draait MySQL?"
    echo "  Instellingen staan in .env.${ENVIRONMENT}."
    exit 1
fi
echo "  database in orde"

if lsof -i ":${PORT}" >/dev/null 2>&1; then
    echo "  Er luistert al iets op poort ${PORT}. Stoppen met: pkill -f 'artisan serve'"
    exit 1
fi
echo "  poort ${PORT} is vrij"

if [ "${1:-}" = "--reset-logins" ]; then
    echo
    echo "== Inloggegevens terugzetten op 'testtest' =="
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
                // een klant met een kapotte database slaan we over
            }
        }
    ' >/dev/null 2>&1
    echo "  gezet"
fi

echo
echo "== Waar je binnenkomt =="
APP_ENV="$ENVIRONMENT" php artisan tinker --execute='
    $port = '"$PORT"';
    echo "  app        http://127.0.0.1:{$port}\n";
    echo "  beheer     http://127.0.0.1:{$port}/beheer\n\n";
    $landlord = \App\Models\Central\LandlordUser::on("central")->first();
    echo "  beheerder  " . ($landlord->email ?? "-- geen beheerder --") . "\n";
    foreach (\App\Models\Tenant::on("central")->orderBy("name")->get() as $tenant) {
        try {
            $email = \App\Support\Tenancy::within($tenant, fn () => \App\Models\User::value("email"));
            echo "  klant      {$tenant->name}: {$email}\n";
        } catch (\Throwable $e) {
            echo "  klant      {$tenant->name}: database niet bereikbaar\n";
        }
    }
    echo "\n  wachtwoord: testtest (zet ze terug met --reset-logins)\n";
' 2>/dev/null | grep -v '^$' | sed 's/^= //'

echo
echo "== Starten (ctrl-c stopt alles) =="

# Vite leest .env rechtstreeks en weet niets van APP_ENV, dus die krijgt het
# adres apart mee; anders wijst hij naar de app-url uit .env, die hier niet
# draait.
#
# APP_ENV als echte omgevingsvariabele en niet als --env: die vlag geldt voor
# het artisan-proces zelf, maar de verzoeken die de server afhandelt starten
# opnieuw op en lezen dan gewoon .env -- met een database die hier niet draait.
npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" \
    "APP_ENV=${ENVIRONMENT} php artisan serve --host=127.0.0.1 --port=${PORT}" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --tries=1" \
    "APP_ENV=${ENVIRONMENT} php artisan queue:listen --queue=provisioning --tries=1" \
    "APP_URL=http://127.0.0.1:${PORT} npm run dev" \
    --names=app,queue,provisioning,vite
