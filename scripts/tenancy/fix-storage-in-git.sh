#!/usr/bin/env bash
# Haalt de uploads van klanten uit git en zet ze veilig terug op schijf.
#
# storage/tenant-<id> stond in de repo: gegevens, geen code. De historie is
# herschreven, dus deze server moet mee -- en dat mag de bestanden niet kosten.
# Volgorde: eerst terugzetten wat er hoort te staan, dan buiten de repo
# parkeren, dan pas de nieuwe historie ophalen, dan terugzetten.
set -euo pipefail
case "$0" in
    */*) cd "${0%/*}/../.." ;;
    *)   cd "$PWD" ;;
esac

BRANCH="${BRANCH:-feature/multi-tenancy}"
PARK="${PARK:-$HOME/tenant-uploads-veilig}"

step() { printf '\n== %s ==\n' "$1"; }
trap 'echo "
  Gestopt op regel ${LINENO}: ${BASH_COMMAND}
  Je bestanden staan in ${PARK} als die map bestaat." >&2' ERR

step "Wat staat er nu"
FOLDERS=$(git ls-files 'storage/tenant-*' | cut -d/ -f2 | sort -u || true)

if [ -z "$FOLDERS" ]; then
    echo "  Deze checkout volgt geen tenant-mappen meer; alleen nog terugzetten."
else
    echo "$FOLDERS" | sed 's/^/  in git: /'
fi

step "Terugzetten wat er mist"
for FOLDER in $FOLDERS; do
    if [ ! -d "storage/${FOLDER}" ] || [ -z "$(ls -A "storage/${FOLDER}" 2>/dev/null)" ]; then
        echo "  storage/${FOLDER} ontbreekt -- uit git terugzetten"
        git restore "storage/${FOLDER}"
    fi
    echo "  storage/${FOLDER}: $(find "storage/${FOLDER}" -type f | wc -l) bestand(en)"
done

step "Buiten de repo parkeren"
mkdir -p "$PARK"
for FOLDER in $(ls storage 2>/dev/null | grep '^tenant-' || true); do
    if [ -d "storage/${FOLDER}" ]; then
        rm -rf "${PARK:?}/${FOLDER}"
        cp -a "storage/${FOLDER}" "${PARK}/${FOLDER}"
        echo "  ${FOLDER} -> ${PARK}/${FOLDER} ($(find "${PARK}/${FOLDER}" -type f | wc -l) bestanden)"
    fi
done

step "Nieuwe historie ophalen"
git fetch origin "$BRANCH"
git reset --hard "origin/${BRANCH}"
echo "  nu op: $(git log --oneline -1)"

step "Bestanden terugzetten"
for FOLDER in $(ls "$PARK" 2>/dev/null || true); do
    mkdir -p "storage/${FOLDER}"
    # cp -r en niet -a: -a wil tijden en eigenaar overzetten, en op mappen die
    # van root zijn levert dat 'Operation not permitted' -- terwijl de bestanden
    # zelf prima te kopiëren zijn. De tijden doen er hier niet toe.
    cp -r "${PARK}/${FOLDER}/." "storage/${FOLDER}/"
    echo "  storage/${FOLDER}: $(find "storage/${FOLDER}" -type f | wc -l) bestand(en)"
done

step "Controle"
if git ls-files 'storage/tenant-*' | grep -q .; then
    echo "  Er staan nog tenant-bestanden in git. Niets opgeruimd; kijk hier eerst naar." >&2
    exit 1
fi

echo "  git volgt geen uploads meer"
echo "  de kopie blijft staan in ${PARK} -- weg te halen zodra je het vertrouwt"

step "Vangnet opruimen"
echo "  De oude historie hangt nog aan de tag backup/voor-storage-purge; zolang die"
echo "  bestaat blijven de 271 MB in de repo staan. Weg te halen met:"
echo "    git push origin :refs/tags/backup/voor-storage-purge"
echo "    git tag -d backup/voor-storage-purge"
