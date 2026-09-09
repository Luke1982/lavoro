#!/usr/bin/env bash
# Deploy voor de multi-tenant installatie.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/.." ;;
    *)   cd "$PWD" ;;
esac

step() { printf '\n== %s ==\n' "$1"; }

step "Onderhoud aan"
php artisan app:maintenance --message="We zijn zo terug." || php artisan down

restore() { php artisan up || true; }
trap restore EXIT

# Zeggen waar het stukliep. Met set -e stopt dit script bij de eerste fout, en
# dat gebeurde tot nu toe zonder een woord: je zag een kopregel en daarna weer
# 'live', en moest het met bash -x opnieuw draaien om te zien waar.
trap 'echo "
  Gestopt op regel ${LINENO}: ${BASH_COMMAND}
  (de melding hierboven hoort daarbij; er is niets uitgerold)" >&2' ERR

step "Back-up van elke database"
STAMP=$(date +%Y-%m-%d_%H-%M-%S)
mkdir -p storage/backups

# De inloggegevens komen uit de app zelf: de centrale uit de configuratie, die
# van elke klant uit de registratie. Zonder gegevens valt mysqldump terug op de
# socket en probeert het als de linux-gebruiker, en die heeft geen
# MySQL-account -- 'Access denied for user lavoro@localhost', vlak nadat het
# onderhoudsscherm aanging.
#
# Klanten waarvan de database niet opengaat worden overgeslagen en genoemd. Van
# een database die er niet meer is valt niets te bewaren, en dat mag de uitrol
# niet tegenhouden.
# Via een eigen commando en niet via tinker: dat is een schil om een REPL die
# zijn eigen meldingen schrijft en een exitcode teruggeeft die niets zegt. En
# de fout blijft zichtbaar -- hier stond 2>/dev/null, precies op het commando
# waarvan de mislukking de hele uitrol afbreekt, zodat er niets te zien was
# behalve een kopregel en daarna weer 'live'.
# Ook stderr erbij, en bij een fout wordt het getoond: artisan schrijft zijn
# foutmelding naar stdout, dus die verdween in deze variabele en er was niets
# te zien behalve een kopregel.
if ! LINES=$(php artisan tenancy:backup-targets 2>&1); then
    echo "  Kon niet opvragen wat er geback-upt moet worden:" >&2
    # Zonder de DUMP-regels: daar staan wachtwoorden in, en die horen niet in
    # de terugmelding of in de scrollback te belanden.
    printf '%s\n' "$LINES" | grep -v '^DUMP' | sed 's/^/    /' >&2 || true
    exit 1
fi

if ! printf '%s\n' "$LINES" | grep -q '^DUMP'; then
    echo "  Geen enkele database om te bewaren -- kwam de centrale database wel op?" >&2
    exit 1
fi

# Met een here-string en niet via een pijp: in 'grep | while' draait de lus in
# een subshell, en alles wat daarbinnen omvalt sterft daar stil. Je zag dan
# alleen dat de pijplijn mislukte, zonder de melding uit de lus zelf -- en een
# exit uit die lus stopte het script niet eens.
while IFS=$'\t' read -r MARK REST; do
    [ "$MARK" = "OVERSLAAN" ] && echo "  overgeslagen (database niet bereikbaar): ${REST}"
done <<< "$LINES"

while IFS=$'\t' read -r MARK DB USER PASS HOST PORT; do
    [ "$MARK" = "DUMP" ] || continue

    # Via een tijdelijk bestand en niet op de opdrachtregel: daar leest iedereen
    # met ps het wachtwoord mee.
    CONFIG=$(mktemp)
    chmod 600 "$CONFIG"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$USER" "$PASS" "$HOST" "$PORT" > "$CONFIG"

    TARGET="storage/backups/${DB}-${STAMP}.sql.gz"

    # --no-tablespaces: het uitlezen van tablespaces vraagt het PROCESS-recht, en
    # dat hebben deze accounts met opzet niet.
    if ! mysqldump --defaults-extra-file="$CONFIG" --single-transaction --no-tablespaces "$DB" | gzip > "${TARGET}.part"; then
        rm -f "$CONFIG" "${TARGET}.part"
        echo "  Back-up van ${DB} mislukt. Er wordt niets uitgerold zonder back-up." >&2
        exit 1
    fi

    rm -f "$CONFIG"

    if ! mv "${TARGET}.part" "$TARGET"; then
        echo "  Kon de back-up van ${DB} niet op zijn plek zetten. Is storage/backups beschrijfbaar voor $(id -un)?" >&2
        exit 1
    fi

    echo "  ${DB} ($(du -h "$TARGET" | cut -f1))"
done <<< "$LINES"

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
# Beide workers echt herstarten, niet alleen een sein geven.
#
# queue:restart zet een vlag in de cache die de worker tussen twee taken door
# oppikt. Dat kwam hier niet aan: na een uitrol meldde de doctor allebei de
# workers nog op de oude code. Php houdt bij het opstarten alles vast, dus tot
# een herstart draait de vorige versie door -- en dat zie je nergens aan
# behalve aan werk dat stilletjes verkeerd gaat.
#
# Het sein blijft er als terugval voor installaties zonder systemd-units.
if sudo -n systemctl restart lavoro-worker lavoro-provisioning 2>/dev/null; then
    echo "  workers herstart"
    # systemctl is terug zodra de unit aan staat, niet zodra php klaar is met
    # opstarten. Zonder deze pauze kijkt de controle hieronder nog naar de
    # vingerafdruk van de vorige worker en meldde ze elke uitrol als 'oude
    # code'. Een melding die er altijd staat leert je ze over te slaan.
    php artisan tenancy:await-workers --timeout=60 || true
else
    php artisan queue:restart
    echo "  Let op: workers alleen een sein gegeven. Draai scripts/tenancy/setup-sudoers.sh"
    echo "  als root, dan mag de uitrol ze zelf herstarten."
fi

# Ook php onder de webserver houdt de gecompileerde code vast. Zonder dit
# draait hij door op de oude klassen terwijl de sjablonen al nieuw zijn.
#
# lsphp eerst, want daar draait deze installatie op. En met -f: pkill vergelijkt
# standaard de procesnaam precies, en die is lsphp8.3 -- 'pkill lsphp' vond dus
# nooit iets. LiteSpeed heeft geen unit; de processen komen vanzelf terug.
#
# systemctl alleen als het zonder wachtwoord kan: als gewone gebruiker vraagt
# reload om een polkit-wachtwoord, en dan staat een uitrol te wachten op iemand
# die niet meekijkt.
if pkill -f lsphp 2>/dev/null; then
    echo "  lsphp herstart (opcache leeg)"
elif sudo -n systemctl reload php8.3-fpm 2>/dev/null; then
    echo "  php-fpm herladen (opcache leeg)"
else
    echo "  Let op: php onder de webserver zelf herstarten, anders draait hij door op de oude code."
fi

step "Controle"
# Twee controles, elk met een eigen bereik: het script kijkt naar de rechten van
# de databaseaccounts (root nodig), de doctor naar de rest van de opstelling.
scripts/tenancy/verify-mysql.sh
# In een if, want de doctor geeft een foutcode zodra hij iets te melden heeft.
# Dat is geen mislukte uitrol -- die is dan al klaar -- en de ERR-trap zei
# daardoor 'er is niets uitgerold' terwijl alles er gewoon stond.
if php artisan tenancy:doctor; then
    echo "
  Klaar. De controle vond niets."
else
    echo "
  Uitgerold. De controle hierboven vond punten die aandacht vragen; de nieuwe
  code draait."
fi

step "Onderhoud uit"
php artisan up
trap - EXIT
echo "Klaar."
