#!/usr/bin/env bash
# Dit script bestaat niet meer -- het hoorde bij de installatie van vóór de
# multi-tenancy en deed twee dingen die daar nu schade aanrichten:
#
#   git reset --hard origin/master   -- gooit de tenancy-branch weg en zet er
#                                       de oude eenklant-versie voor terug
#   php artisan migrate --force      -- draait de migraties van die versie over
#                                       de centrale database heen
#
# De backup ervoor liep bovendien met de verkeerde login, meldde 'saved' en
# schreef een leeg bestand: precies wanneer je hem nodig hebt is hij er niet.
set -euo pipefail

cat >&2 <<'MELDING'
Dit is het oude deploy-script en het doet meer kwaad dan goed.

Gebruik:

    scripts/tenancy/deploy.sh

Die maakt een back-up van de centrale database én van elke klant, haalt de
huidige branch op (en niet master), draait de migraties van zowel centraal als
elke klant, en kijkt achteraf met tenancy:doctor of het klopt.
MELDING

exit 1
