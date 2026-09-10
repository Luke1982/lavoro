#!/usr/bin/env bash
# This script no longer exists -- it belonged to the installation from before
# the multi-tenancy and did two things that now do damage:
#
#   git reset --hard origin/master   -- throws the tenancy branch away and puts
#                                       the old single-customer version back
#   php artisan migrate --force      -- runs that version's migrations over the
#                                       central database
#
# The backup before it also ran with the wrong login, reported 'saved' and wrote
# an empty file: exactly when you need it, it is not there.
set -euo pipefail

cat >&2 <<'MELDING'
This is the old deploy script and it does more harm than good.

Use:

    scripts/deploy.sh

That one backs up the central database and every customer's, fetches the current
branch (and not master), runs the migrations for both central and every
customer, and checks afterwards with tenancy:doctor whether it is right.
MELDING

exit 1
