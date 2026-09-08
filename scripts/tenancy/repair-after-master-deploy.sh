#!/usr/bin/env bash
# Herstel na een ronde met het oude deploy.sh.
#
# Dat script deed 'git reset --hard origin/master' en draaide daarna de
# migraties van de eenklant-versie over de centrale database. Dit zet de
# installatie terug op de juiste branch, ruimt op wat die migratie half heeft
# achtergelaten, gooit de mislukte back-up weg en rolt daarna normaal uit.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

# SKIP_DEPLOY=1 doet alleen het herstel; YES=1 vraagt niets.
BRANCH="${1:-feature/multi-tenancy}"
YES="${YES:-}"

step() { printf '\n== %s ==\n' "$1"; }

vraag() {
    [ -n "$YES" ] && return 0
    printf '   %s [j/N] ' "$1"
    read -r answer </dev/tty
    [ "$answer" = "j" ] || [ "$answer" = "J" ]
}

step "Waar staan we nu"
echo "   branch: $(git rev-parse --abbrev-ref HEAD)"
echo "   commit: $(git log --oneline -1)"

step "Terug naar ${BRANCH}"
git fetch origin "$BRANCH"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "   Er staan lokale wijzigingen in gevolgde bestanden:"
    git status --short --untracked-files=no | sed 's/^/     /'
    vraag "Weggooien en terug naar origin/${BRANCH}?" || { echo "   Gestopt."; exit 1; }
fi

git reset --hard "origin/${BRANCH}"
echo "   nu op: $(git log --oneline -1)"

step "Mislukte back-ups opruimen"
# Het oude script meldde 'saved' terwijl mysqldump was afgeketst op de
# verkeerde login. Zo'n bestand is leeg en juist als je hem nodig hebt is er niets.
found=0
while IFS= read -r -d '' file; do
    echo "   leeg: ${file}"
    found=1
    [ -n "$YES" ] && rm -f "$file"
done < <(find storage/backups -name '*.sql.gz' -size -1k -print0 2>/dev/null)

if [ "$found" = "0" ]; then
    echo "   geen lege back-ups gevonden"
elif [ -z "$YES" ]; then
    vraag "Deze weggooien?" && find storage/backups -name '*.sql.gz' -size -1k -delete
fi

step "Tabellen die daar niet horen"
# create_users_table maakt users, password_reset_tokens en sessions in één
# bestand en liep stuk op sessions. MySQL draait DDL niet terug, dus de eerste
# twee kunnen nu in de centrale database staan. sessions hoort er wél te zijn.
# tinker vangt exit() zelf af en geeft altijd 1 terug, dus het antwoord komt
# als regel terug en niet als exitcode.
strays=$(php artisan tinker --execute='
    $strays = collect(["users", "password_reset_tokens"])
        ->filter(fn ($table) => Schema::connection("central")->hasTable($table))
        ->mapWithKeys(fn ($table) => [$table => DB::connection("central")->table($table)->count()]);

    foreach ($strays as $table => $rows) {
        echo "GEVONDEN {$table} {$rows}\n";
    }

    echo "STATUS=" . ($strays->isEmpty() ? "geen" : ($strays->contains(fn ($rows) => $rows > 0) ? "data" : "leeg")) . "\n";
' 2>/dev/null || true)

# grep geeft 1 terug als er niets staat, en met set -e stopt het script daar
# dan zonder een woord -- precies wat je niet wilt in een herstelscript.
echo "$strays" | grep '^GEVONDEN' 2>/dev/null | while read -r _ table rows; do
    echo "   ${table}: ${rows} rij(en)"
done || true

status=$(echo "$strays" | grep -o 'STATUS=[a-z]*' | tail -1 || true)

case "$status" in
    STATUS=geen)
        echo "   niets gevonden"
        ;;
    STATUS=leeg)
        if vraag "Deze lege tabellen weghalen uit de centrale database?"; then
            php artisan tinker --execute='
                foreach (["users", "password_reset_tokens"] as $table) {
                    if (Schema::connection("central")->hasTable($table)) {
                        Schema::connection("central")->drop($table);
                        echo "   {$table} weg\n";
                    }
                }
            ' 2>/dev/null
        fi
        ;;
    STATUS=data)
        echo "   Er staat data in. Niet automatisch weggegooid -- kijk er eerst naar."
        ;;
    *)
        echo "   Niet na te gaan (kwam de centrale database wel op?)."
        ;;
esac

if [ -n "${SKIP_DEPLOY:-}" ]; then
    step "Klaar"
    echo "   Uitrollen overgeslagen. Doe dat met: scripts/tenancy/deploy.sh"
    exit 0
fi

step "Normaal uitrollen"
exec scripts/tenancy/deploy.sh
